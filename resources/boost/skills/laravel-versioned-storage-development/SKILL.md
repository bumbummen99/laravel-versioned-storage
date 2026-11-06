---
name: laravel-versioned-storage-development
description: >
  Configure and apply the Laravel Versioned Storage package in Laravel applications.
license: AGPL-3.0-only with the Library Integration Exception; see LICENSE.md and COPYING.EXCEPTION
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
- Require a Unix-like PHP runtime; native Windows PHP is unsupported because the required Spatie Fork dependency needs PCNTL. Windows hosts must use WSL2 or Linux containers. CLI uploads and queue workers require PCNTL/POSIX/sockets; web uploads require PHP-FPM with a hard termination limit.
- Identify the target disk, logical file or directory path, and whether consumers need historical versions.

### 2. Install and configure

```bash
composer require skyraptor/laravel-versioned-storage
php artisan vendor:publish --tag="laravel-versioned-storage-migrations"
php artisan migrate
```

Optionally publish `laravel-versioned-storage-config`. Set `disk` and `connection` in `config/laravel-versioned-storage.php` to named Laravel filesystem/database connections, or leave them `null` for the application defaults. Configure the metadata connection before migrating.

Set `upload_timeout` (default 60 seconds) to the externally enforced PHP-FPM hard limit. Verify FPM `request_terminate_timeout`; PHP `max_execution_time` and Nginx `fastcgi_read_timeout` alone do not kill blocked upload workers. Forge's execution-time setting should align all three. Active `queue:work`/`horizon:work` jobs use their `$timeout` or the worker's actual `--timeout` and require working PCNTL/POSIX enforcement; keep queue `retry_after`/visibility longer than that timeout. `--once` jobs use a supervised fork instead.

CLI outside daemon jobs and `artisan serve` (`cli-server`) use Spatie Fork with a fresh child-owned alarm per filesystem operation; selection is SAPI-based, not `APP_ENV`. The directory callback and all package database work stay in the parent; children only resolve the disk and perform filesystem work. In-memory SQLite metadata is supported. Fork mode requires Unix/PCNTL/POSIX/sockets and no active transactions on resolved connections at upload entry. Provide reproducible disk configuration/custom drivers; do not use inherited remote clients or database connections inside drivers. Parent SIGINT/SIGTERM/SIGQUIT kills children; after parent SIGKILL the child's alarm still bounds it. Database commits/publication and arbitrary callback code do not have a child timeout.

Set `cleanup_grace` (default 10800 seconds) longer than the maximum accepted remote-write completion delay after termination. Each mutation journal has a fixed operation-start + timeout + grace deadline rounded up to whole seconds; forked deadlines include one extra second for alarm rounding. Successful operations remove their journals before the next starts. Uncertain operations retain them. The stage's recovery deadline advances before each operation and never shortens. External/deferred writes beyond this contract cannot be universally fenced. Publish/run the operation-journal migration with writers/cleanup paused and old workers drained; do not mix the former whole-upload path with per-operation writers.

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

Filesystem operations inside the callback get fresh child timeouts where fork supervision applies, including reads and listing enumeration. Forked `readStream()` buffers the complete remote read into a temporary local stream; listings are materialized. Allow temporary disk space. Mutations (write/stream, mkdir, copy/move, delete, visibility) additionally commit a journal before execution. Any operation failure poisons the whole stage even when caught by the callback; further operations and publication fail. Do not retain the callback filesystem for later use or bypass it through raw adapters. FPM/daemon timeouts still bound the whole request/job, not each operation.

Each resource must exclusively own its physical version container, whose immediate entries are only that resource's UUID versions. Never share a container between disk names, logical paths, or file and directory resources. Nested disk roots are allowed when resource containers remain separate; do not place a resource inside another resource's container or snapshot. Developers must ensure exclusivity across disk configurations and external writes; automatic detection of every physical overlap is not guaranteed. Existing guards reject separate resources with identical or nested logical roots sharing a Flysystem adapter before writing, including file/directory mixing and disk aliases. Further versions of the same resource remain allowed. UUIDs in logical paths and arbitrary nested callback contents remain allowed.

Retired disks without a configured driver or registered instance do not block independent disks' registration or cleanup; their metadata and files remain untouched. Explicitly targeting a retired disk still fails, as do resolution errors from configured disks.

Scoped public and temporary download URLs retain the selected disk's URL behavior and target the specific snapshot. External URL reads are not protected against cleanup. Temporary upload URLs are rejected: `providesTemporaryUploadUrls()` returns `false` and `temporaryUploadUrl()` throws, even with custom upload URL builders. Write all snapshot contents before the callback returns.

Files use `<path>/<version>`; directory children use `<directory>/<version>/<child>`. Read through this API rather than through `Storage::get($logicalPath)`.

Paths remain relative to the selected disk's configured prefix, including Laravel scoped disks; do not prepend that prefix again when calling the package.

Snapshot copy/move operations follow Laravel's visibility configuration, including disabled visibility retention for Cloudflare R2 endpoints.

Snapshot operations preserve restrictions implemented by the selected disk's Flysystem operator. Write failures throw even if the original disk has `throw=false`.

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
- Staging commits a snapshot stage, then uses short parent-owned transactions before/after each filesystem operation, without holding database transactions during storage I/O. Confirmed successes remove mutation journals; failed acknowledgements leave them recoverable and prevent publication. The stage becomes ready only when the entire callback succeeds with no outstanding journals. Uncertain mutations defer snapshot deletion until safe recovery. Incomplete stages cannot be discarded early. Ready stages never expire and remain hidden until publication; explicit discard remains retryable.
- Publish retries return the same UUID without repointing the resource or repeating cleanup, including after later retirement. Published-stage records and UUID reservations remain for idempotency. Published stages cannot be discarded.
- A failing transaction-beginning listener rolls back only the package operation's newly opened transaction or savepoint, preserving existing outer transaction work.
- Exceptions after commit propagate without deleting committed snapshots. Failed commits may leave PDO active with Laravel depth zero; package recovery rolls back that transaction before resolving publication state. Applications must use Laravel transaction APIs.
- Current and explicit-version `get()`/`exists()` hold resource locks through the filesystem read. Publication waits for existing readers before committing retirement; the command then deletes irreversibly retired snapshots without holding DB locks during worker I/O. Never manually revive retired metadata.
- Reads are serialized per resource, with each directory child read protected separately. Locks inside an application transaction last until it ends; keep transactions short and acquire resources in a consistent order. SQLite locks are database-wide.
- Recovery locks and claims due stages only after their recovery and outstanding mutation deadlines. It removes the whole unpublished snapshot, including earlier successful files. A paused callback cannot resume writing after cleanup claims its stage. MySQL/InnoDB/PostgreSQL use row locks (requires SKIP LOCKED support for recovery, e.g. MySQL 8+); SQLite serializes metadata writes. Keep application publication transactions short.
- Metadata reads use the primary database, not read replicas. Live stage objects retain their original connection/filesystem. Recovery needs database/storage availability and does not fence ongoing remote writes after database-session loss.
- First registrations reserve roots under a shared database lock; this protects existing overlap guards, not arbitrary physical overlap detection. Failed writes can leave an empty reservation.
- Duplicate version UUIDs and occupied destination paths fail before writes. Committed UUID reservations survive cleanup and failed writes. Publish/run the reservations and staging migrations with package writers paused when upgrading; older untracked crash files cannot be backfilled.
- Legacy resources inside physical version directories block destructive cleanup with an explicit error and retain retry records; resolve the ownership conflict before retrying.

## Rules, References, and Templates

- Publish tag: `laravel-versioned-storage-migrations`.
- Optional config tag: `laravel-versioned-storage-config`.
- Combined config and migrations tag: `laravel-versioned-storage`. Run `php artisan migrate` after publishing.
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

In Laravel application tests, migrate the package tables, fake the selected disk, and call `SkyRaptor\LaravelVersionedStorage\Testing\VersionedStorageTesting::runUploadsSynchronously()` in setup. This explicitly runs real uploads in-process, supporting in-memory SQLite and callback assertions without faking FPM. It is restricted to Laravel's testing environment, is local to the application container, and must be enabled for each fresh test application. `restoreUploadExecution()` restores the normal runtime policy.

Use `DatabaseMigrations` or another setup without an enclosing metadata transaction, not transactional `RefreshDatabase`/`DatabaseTransactions`. Assert public reads and physical paths on the fake disk. For cleanup command tests with fake disks/in-memory SQLite, explicitly set `concurrency.default` to `sync`; the helper does not change cleanup concurrency. Synchronous uploads retain journal deadlines but do not enforce a hard timeout; keep real-process timeout tests separate.

## Anti-patterns

- Do not skip the database migration.
- Do not use `cleanup: true` if consumers need to start future reads of old versions.
- Do not bypass Laravel transaction APIs/events with raw PDO commits or rollbacks.
- Do not assume completed stages expire: explicitly publish or discard them. Do not treat database-session loss during an ongoing remote upload as safely fenced.
- Do not swallow callback/write failures: incomplete versions must not become current.
- Do not depend on private metadata schema or mutate published snapshots directly.
- Do not mix resources' UUID versions in one physical container, including through different disks or file/directory APIs.
