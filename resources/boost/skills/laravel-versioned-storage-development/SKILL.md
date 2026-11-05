---
name: laravel-versioned-storage-development
description: >
  Configure and apply the Laravel Versioned Storage package in Laravel applications.
license: MIT
metadata:
  author: Patrick Henninger
---

# Laravel Versioned Storage

Use this skill when a Laravel application needs to integrate the Laravel Versioned Storage package.

## Primary Goal

- apply the `skyraptor/laravel-versioned-storage` package's public API in the smallest correct way

## Workflow

### 1. Inspect the Laravel app context

- Confirm the app uses Laravel 12 or 13 and PHP 8.3+.
- Identify the target disk, logical file or directory path, and whether consumers need historical versions.

### 2. Install and configure

```bash
composer require skyraptor/laravel-versioned-storage
php artisan vendor:publish --tag="laravel-versioned-storage-migrations"
php artisan migrate
```

Optionally publish `laravel-versioned-storage-config`. Set `disk` and `connection` in `config/laravel-versioned-storage.php` to named Laravel filesystem/database connections, or leave them `null` for the application defaults. Configure the metadata connection before migrating.

Set `upload_timeout` (default 60 seconds) to the externally enforced PHP-FPM hard limit. Verify FPM `request_terminate_timeout`; PHP `max_execution_time` and Nginx `fastcgi_read_timeout` alone do not kill blocked upload workers. Forge's execution-time setting should align all three. Active `queue:work`/`horizon:work` jobs use their `$timeout` or the worker's actual `--timeout` and require working PCNTL/POSIX enforcement; keep queue `retry_after`/visibility longer than that timeout. `--once` jobs use a supervised fork instead.

CLI outside daemon jobs and `artisan serve` (`cli-server`) use Spatie Fork with a child-owned OS alarm and `upload_timeout`; selection is SAPI-based, not `APP_ENV`. Fork mode requires Unix/PCNTL/POSIX/sockets, a persistent database (not in-memory SQLite), no active transactions on resolved connections, and fresh named disks. Database connections disconnect before forking. Do not capture live PDO/remote clients; callback mutations do not reach parent memory. Parent SIGINT/SIGTERM/SIGQUIT kills children; after parent SIGKILL the child's own alarm still bounds it. Unsupported runtimes and unbounded limits fail explicitly.

Set `cleanup_grace` (default 10800 seconds) longer than the maximum accepted remote-write completion delay after termination. Each incomplete intent has an immutable start + effective timeout + grace deadline; later config changes do not shorten it. External/deferred writes beyond this contract cannot be universally fenced. Publish the new migration on upgrades and drain old unbounded writers; existing intents receive a fresh migration-time deadline.

### 3. Apply the public API

Use `SkyRaptor\LaravelVersionedStorage\VersionedStorage` or `SkyRaptor\LaravelVersionedStorage\Facades\VersionedStorage`.

- `put($path, $contents, $cleanup = false, $disk = null)` returns a version string.
- `stage($path, $contents, $disk = null)` and `stageDirectory($path, $callback, $disk = null)` return a completed, unpublished stage with a `version` UUID.
- `$stage->publish($cleanup = false)` publishes that stage, optionally inside an application transaction. `$stage->discard()` removes an unpublished stage outside transactions.
- `staged($uuid)` reloads a stage in another process/job; pass UUIDs, not serialized handlers.
- `get($path, $version = null, $disk = null)` reads the selected or current version.
- `exists($path, $version = null, $disk = null)` checks a selected or current file.
- `versions($path, $disk = null)` lists versions oldest first.
- `cleanup($path, $disk = null)` retries pending file-version deletions without creating a version.
- `directory($path, $callback, $cleanup = false, $disk = null)` writes a new independent snapshot and returns its version.
- `directory($path)` returns a handler with `get($file, $version = null)`, `exists($file, $version = null)`, `versions()`, and `cleanup()`.

The directory callback receives a scoped Laravel `FilesystemAdapter`. All files in the new snapshot must be written by that callback; previous files are not copied.

Each resource must exclusively own its physical version container, whose immediate entries are only that resource's UUID versions. Never share a container between disk names, logical paths, or file and directory resources. Nested disk roots are allowed when resource containers remain separate; do not place a resource inside another resource's container or snapshot. Developers must ensure exclusivity across disk configurations and external writes; automatic detection of every physical overlap is not guaranteed. Existing guards reject separate resources with identical or nested logical roots sharing a Flysystem adapter before writing, including file/directory mixing and disk aliases. Further versions of the same resource remain allowed. UUIDs in logical paths and arbitrary nested callback contents remain allowed.

Retired disks without a configured driver or registered instance do not block independent disks' registration or cleanup; their metadata and files remain untouched. Explicitly targeting a retired disk still fails, as do resolution errors from configured disks.

Scoped public and temporary download URLs retain the selected disk's URL behavior and target the specific snapshot. External URL reads are not protected against cleanup. Temporary upload URLs are rejected: `providesTemporaryUploadUrls()` returns `false` and `temporaryUploadUrl()` throws, even with custom upload URL builders. Write all snapshot contents before the callback returns.

Files use `<path>/<version>`; directory children use `<directory>/<version>/<child>`. Read through this API rather than through `Storage::get($logicalPath)`.

Paths remain relative to the selected disk's configured prefix, including Laravel scoped disks; do not prepend that prefix again when calling the package.

Snapshot copy/move operations follow Laravel's visibility configuration, including disabled visibility retention for Cloudflare R2 endpoints.

`put()` accepts strings, streams, and Laravel `File`/`UploadedFile` objects. Uploaded files are streamed into the exact version filename.

### 4. Coordinate cleanup and transactions

- Pass `cleanup: true` to hide previous versions transactionally. Only the cleanup command (or explicitly invoked resource-specific cleanup APIs) deletes retired snapshots; writes, commits, and application termination do not automatically clean up.
- Snapshot removal verifies absence before discarding metadata, adding filesystem existence checks (remote requests on remote disks). Failed deletions retain pending records. Explicit `cleanup()` APIs run synchronously and throw on failure; cleanup refuses active metadata transactions.
- Run or schedule `php artisan laravel-versioned-storage:cleanup` to retry pending deletions across resources. It fails explicitly if any deletion still fails.
- The command first recovers interrupted staging intents under locks (additional metadata writes), then uses ID-ordered chunks (default `--chunk=10`) and Laravel `Concurrency::run()`, with one bulk metadata DELETE per chunk for verified successes. Failures stay pending and later chunks continue. Workers perform filesystem work only; chunk loading and directory ownership validation still read metadata.
- Laravel's configured concurrency driver is used (`process` by default). Named disks/custom drivers must be configured identically in worker applications. Runtime-only disks require an explicit suitable driver such as `sync` (sequential); `fork` requires CLI/`pcntl` and fork-safe connections. There is no silent fallback.
- In the application's `routes/console.php`, use `Schedule::command('laravel-versioned-storage:cleanup')->everyFiveMinutes()->withoutOverlapping()` with `Illuminate\Support\Facades\Schedule`. Ensure Laravel's scheduler is running; failed deletions remain pending for the next attempt.
- Upload APIs reject existing Laravel/raw PDO metadata transactions before writing. Stage first, then publish inside the application's transaction on the same connection. Outer/nested rollback restores the completed stage, previous current version, and retirement flags without deleting snapshots. Republish or explicitly discard after rollback.
- Schedule the cleanup command for periodic physical deletion; no automatic termination cleanup or queue worker is used. Command disks must be available in the command's application configuration.
- Staging commits an intent before filesystem writes, then holds an internal transaction/staging-row lock through the upload. A process crash leaves a durable incomplete intent for cleanup after its persisted deadline; no second connection is used. Failed uploads retain intents until that deadline even if immediate removal succeeds, so late remote completion stays tracked. Incomplete stages cannot be discarded early. Explicit ready-stage discard is immediate and failed removal remains retryable. Ready stages never expire and remain hidden until publication.
- Publish retries return the same UUID without repointing the resource or repeating cleanup, including after later retirement. Published-stage records and UUID reservations remain for idempotency. Published stages cannot be discarded.
- A failing transaction-beginning listener rolls back only the package operation's newly opened transaction or savepoint, preserving existing outer transaction work.
- Current and explicit-version `get()`/`exists()` hold resource locks through the filesystem read. Publication waits for existing readers before committing retirement; the command then deletes irreversibly retired snapshots without holding DB locks during worker I/O. Never manually revive retired metadata.
- Staging holds a transaction during storage I/O. MySQL/InnoDB and PostgreSQL use row locks and recovery skips locked stages (requires server support for SKIP LOCKED, e.g. MySQL 8+). SQLite serializes writers; recovery may wait or report contention without deleting a live upload. Keep application publication transactions short.
- Metadata reads use the primary database, not read replicas. Live stage objects retain their original connection/filesystem. Recovery needs database/storage availability and does not fence ongoing remote writes after database-session loss.
- First registrations reserve roots under a shared database lock; this protects existing overlap guards, not arbitrary physical overlap detection. Failed writes can leave an empty reservation.
- Duplicate version UUIDs and occupied destination paths fail before writes. Committed UUID reservations survive cleanup and failed writes. Publish/run the reservations and staging migrations with package writers paused when upgrading; older untracked crash files cannot be backfilled.
- Legacy resources inside physical version directories block destructive cleanup with an explicit error and retain retry records; resolve the ownership conflict before retrying.

## Rules, References, and Templates

- Publish tag: `laravel-versioned-storage-migrations`.
- Optional config tag: `laravel-versioned-storage-config`.
- See the package README for cleanup and failure semantics.

## Examples

```php
use Illuminate\Filesystem\FilesystemAdapter;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

$version = VersionedStorage::directory('exports/monthly', function (FilesystemAdapter $files): void {
    $files->put('summary.txt', 'Monthly summary');
});

$contents = VersionedStorage::directory('exports/monthly')->get('summary.txt', $version);
```

In tests, migrate the package tables and fake the selected Laravel disk. Assert both public reads and the expected physical paths on that disk.

## Anti-patterns

- Do not skip the database migration.
- Do not use `cleanup: true` if consumers need to start future reads of old versions.
- Do not bypass Laravel transaction APIs/events with raw PDO commits or rollbacks.
- Do not assume completed stages expire: explicitly publish or discard them. Do not treat database-session loss during an ongoing remote upload as safely fenced.
- Do not swallow callback/write failures: incomplete versions must not become current.
- Do not depend on private metadata schema or mutate published snapshots directly.
- Do not mix resources' UUID versions in one physical container, including through different disks or file/directory APIs.
