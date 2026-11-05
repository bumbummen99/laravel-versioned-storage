<div align="center">
    <h1>Laravel Versioned Storage</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/skyraptor/laravel-versioned-storage"><img src="https://img.shields.io/packagist/v/skyraptor/laravel-versioned-storage.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/skyraptor/laravel-versioned-storage"><img src="https://img.shields.io/packagist/php-v/skyraptor/laravel-versioned-storage.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/skyraptor/laravel-versioned-storage"><img src="https://badge.laravel.cloud/badge/skyraptor/laravel-versioned-storage?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/skyraptor/laravel-versioned-storage/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/skyraptor/laravel-versioned-storage/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/skyraptor/laravel-versioned-storage"><img src="https://img.shields.io/packagist/dt/skyraptor/laravel-versioned-storage.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Versioned storage for Laravel. Each write uses a unique filesystem path; the database tracks available versions and the current version. A directory snapshot becomes current only after its write callback has completed successfully.

## Installation

### Requirements

- PHP 8.3+ and Laravel 12 or 13.
- A Unix-like PHP runtime with PCNTL, POSIX, and sockets extensions for supervised CLI uploads and queue workers.
- PHP-FPM with a configured hard request termination limit for web uploads.

**Native Windows PHP is not supported.** The required `spatie/fork` dependency requires PCNTL, which is unavailable on Windows. On Windows hosts, run PHP inside WSL2 or a Linux container instead. These execution requirements preserve bounded upload lifetimes; there is no unprotected Windows fallback.

You can install the package via Composer:

```bash
composer require skyraptor/laravel-versioned-storage
```

The package provides configuration and database migrations. You may publish both at once, then run the migrations:

```bash
php artisan vendor:publish --tag="laravel-versioned-storage"
php artisan migrate
```

Or publish them individually. Publishing configuration is optional; publishing and running the migrations is required:

### Publishing the Configuration File

```bash
php artisan vendor:publish --tag="laravel-versioned-storage-config"
```

### Publishing and Running the Migrations

```bash
php artisan vendor:publish --tag="laravel-versioned-storage-migrations"
php artisan migrate
```

## Usage

### Files

```php
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

$first = VersionedStorage::put('reports/daily.csv', 'first contents');
$second = VersionedStorage::put('reports/daily.csv', 'second contents');

VersionedStorage::get('reports/daily.csv'); // second contents
VersionedStorage::get('reports/daily.csv', $first); // first contents
VersionedStorage::exists('reports/daily.csv', $first); // true
VersionedStorage::versions('reports/daily.csv'); // [$first, $second]
```

The contents of `reports/daily.csv` are stored at `reports/daily.csv/<version>` on the selected disk, not at the original logical path. Paths are relative to the disk root; leading slashes are normalized.

`put()` also accepts Laravel `File` and `UploadedFile` objects; their contents are streamed into that exact version filename.

### Directory Snapshots

```php
use Illuminate\Filesystem\FilesystemAdapter;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

$version = VersionedStorage::directory('reports/monthly', function (FilesystemAdapter $files): void {
    $files->put('summary.txt', 'Monthly summary');
    $files->put('details/data.csv', 'Monthly details');
});

$directory = VersionedStorage::directory('reports/monthly');
$directory->get('summary.txt'); // current version
$directory->get('details/data.csv', $version); // selected version
$directory->exists('summary.txt', $version);
$directory->versions(); // oldest to newest
```

Directory files are stored at `reports/monthly/<version>/summary.txt` and `reports/monthly/<version>/details/data.csv`. The callback receives a Laravel filesystem scoped to the new version. Each snapshot is independent: files from previous versions are not copied automatically.

All storage paths are relative to the selected disk's configured prefix, including Laravel scoped disks. For example, a disk prefixed with `tenants/acme` stores the snapshot at `tenants/acme/reports/monthly/<version>/...` beneath its root. Reads, writes, cleanup, and rollback use that same prefix.

Snapshot copy and move operations preserve Laravel's visibility configuration, including its `retain_visibility=false` setting for Cloudflare R2 endpoints.

Snapshot operations delegate to the selected disk's existing Flysystem operator, preserving operator-level restrictions and configuration rather than accessing its raw adapter directly. Write failures still throw even when the disk's Laravel `throw` option is disabled.

The scoped filesystem preserves the selected disk's public and temporary download URL behavior, including custom URL builders. URLs target that specific snapshot; external URL requests do not acquire the package's read locks and can fail after cleanup removes the version. Temporary upload URLs are explicitly unsupported: `providesTemporaryUploadUrls()` returns `false`, and `temporaryUploadUrl()` throws a `RuntimeException`, even if the disk supports them or a custom upload URL builder is configured. All snapshot contents must be written before the callback returns; deferred uploads would allow writes after publication.

### Exclusive Version Containers and Nested Disks

Each resource exclusively owns its version container: the directory at its logical path, resolved against the selected disk's root and prefix. A versioned file `reports/daily.csv` uses `reports/daily.csv/<uuid>` for its file versions; a versioned directory `reports/monthly` uses `reports/monthly/<uuid>/...` for its directory snapshots. The container's immediate entries are reserved exclusively for that resource's UUID versions.

The following configurations are invalid and unsupported, even if they appear to work:

- Using different disk names or logical paths that resolve to the same physical version container for separate resources.
- Using the same physical version container for both a versioned file and a versioned directory.
- Placing another resource's container or unrelated files inside a version container or its snapshots. Snapshot contents must be written through that snapshot's callback, not independently modified after publication.

Nested disk roots are allowed. For example, disk `main` rooted at `/storage` can store `exports/daily.csv`, while disk `tenant` rooted at `/storage/tenants/acme` stores `reports/monthly`. Their containers are separate. However, `main` with path `tenants/acme/reports/monthly` and `tenant` with path `reports/monthly` resolve to the same container and must not be used as separate resources.

Developers are responsible for keeping these containers exclusive across disk configurations, application installations, and external writes. The package does not promise to detect every physical overlap across filesystem drivers. Existing overlap guards are safeguards, not support for sharing or merging containers: separate resources with identical or nested logical roots sharing a Flysystem adapter are rejected with an `InvalidArgumentException` before writing or invoking a callback. This includes switching between file and directory APIs at the same root or registering that root through a disk alias. Further versions of the same resource remain allowed. UUIDs in logical paths and arbitrary files and subdirectories within a directory snapshot remain allowed.

Metadata for a retired disk with no configured driver or registered instance does not block registration or cleanup on independent disks. Its metadata and files remain untouched. Operations explicitly targeting that disk still fail, and resolution errors from configured disks are not ignored.

### Cleanup and Errors

Pass `cleanup: true` to `put()` or `directory()` to retire all previously published versions of that resource. They become unavailable through the package API immediately within the transaction; physical deletion is left to the cleanup command. Other resources and unregistered files are left untouched.

Writes complete before the current-version pointer is changed in a database transaction. Concurrent publications of the same resource are serialized through a persistent database row. Short metadata transactions track the stage and each filesystem mutation without holding a transaction through upload I/O. Failed operations or callbacks cannot publish a partial snapshot; uncertain mutations retain journals and defer deletion until safe recovery. Missing versions return `false` from `exists()` and raise an exception from `get()`. Filesystem and database failures also raise exceptions.

Resource roots are reserved before staging files. First registrations are serialized using a shared database lock so concurrent writers cannot bypass the overlap guards. This does not detect every physical overlap or make invalid disk configurations safe. A failed write can leave an empty resource reservation with no current version; it is not a published snapshot.

Version UUIDs are reserved atomically in the metadata database before touching their filesystem paths. A duplicate UUID raises an exception without invoking the write callback or modifying the existing snapshot; it is not automatically retried. An already occupied destination path is also rejected without deleting its contents. Committed UUID reservations are retained after failed writes and cleanup so retired identifiers cannot be reused. Staging and UUID reservation run outside application transactions.

Existing installations must publish and run the reservations and staging migrations before using this behavior; pause package writers during the upgrade. The reservations migration reserves all versions already present in the metadata tables. Previously deleted version identifiers and untracked files from crashes before this upgrade cannot be recovered or backfilled.

Old versions are marked for deletion in the publication transaction and hidden from public reads/lists. Writes, transaction commits, and application termination never automatically delete retired snapshots. Run or schedule the cleanup command to remove them. It selects committed pending-deletion records, continues attempting other deletions after failures, and retains failed records for retry.

To delete pending snapshots synchronously without publishing another version:

```php
VersionedStorage::cleanup('reports/daily.csv');
VersionedStorage::directory('reports/monthly')->cleanup();
```

These explicit APIs remain available for manual resource-specific cleanup and throw on failure. Cleanup refuses to run inside an active metadata transaction rather than deleting uncommitted data or controlling the application's transaction. Upload and publication failures still throw from `put()`/`directory()`; physical deletion of retired revisions is not part of publication.

Snapshot removal includes a post-delete existence check before its metadata is discarded. If the path remains, or verification throws, cleanup fails and retains the pending-deletion record. This also catches adapters that silently ignore a server's deletion refusal. Verification adds filesystem existence checks to snapshot removal, including remote requests for remote disks.

To retry pending deletions across all resources on the configured metadata connection, run or schedule:

```bash
php artisan laravel-versioned-storage:cleanup
```

The command returns a failure exit code if any deletion or recovery operation fails. It also recovers interrupted uploads from their durable staging intents, and committed cleanup work left by a process that stopped before deleting the files.

The command loads revisions in ID-ordered chunks and deletes snapshots concurrently through Laravel's `Concurrency::run()`. The default chunk size is 10, bounding the number of concurrent tasks. To change it:

```bash
php artisan laravel-versioned-storage:cleanup --chunk=20
```

Each worker deletes and verifies one snapshot, returning its result without updating package metadata. The parent removes confirmed successes with one bulk database `DELETE` per chunk (none if every deletion failed). Before that pass, interrupted staging intents are claimed under database locks and converted transactionally into pending deletions; this recovery pass needs additional metadata writes. Chunk loading and directory ownership checks still require reads; this is not one total database query per chunk. Failed or uncertain deletions stay pending, and failures in an early chunk do not block later chunks. Each pass has an ID bound so newly created work can wait for the next run. If a worker/process or database update fails after files were deleted, the next run safely reconciles the already-absent snapshots.

The configured Laravel concurrency driver is used (`process` by default). Process workers boot independent applications: use named disks configured identically in the worker application, including custom driver registration and credentials. Missing disks or configuration mismatches fail explicitly and retain metadata. Runtime-only disks are not automatically transferred; use Laravel's explicit `sync` driver when same-process storage is required (this disables parallel execution). The optional `fork` concurrency driver requires CLI/`pcntl` and fork-safe adapters/connections; arbitrary in-memory or inherited remote-connection disks are not guaranteed safe. No concurrency failure silently switches to sequential execution.

Committed retirement is irreversible through the package APIs. Publication takes the resource lock before marking versions pending, waiting for existing protected readers to finish; later readers cannot select retired versions. Therefore command workers can delete retired snapshots without holding database locks during remote I/O. Current revisions are excluded, legacy nested-resource checks remain in place, and the final bulk delete rechecks retirement/current-version metadata. Resource-specific cleanup APIs retain their existing sequential locking behavior.

For periodic retries, add this schedule to your application's `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('laravel-versioned-storage:cleanup')
    ->everyFiveMinutes()
    ->withoutOverlapping();
```

Ensure Laravel's scheduler is running, typically through a cron entry invoking `php artisan schedule:run` every minute. Failed deletions remain pending for the next scheduled attempt.

If legacy metadata already registers another resource inside a version directory, deletion of that directory fails explicitly and retains its pending-deletion record. Move or resolve the conflicting resource before retrying cleanup; it is not deleted automatically.

### Transactions and Concurrent Reads

**Uploads inside an existing metadata transaction are no longer supported.** `put()`, `directory($path, $callback)`, `stage()`, and `stageDirectory()` reject both Laravel and raw PDO transactions before registering resources or writing files. Stage outside the transaction, then publish the completed snapshot inside it:

```php
use Illuminate\Support\Facades\DB;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

$staged = VersionedStorage::stage('reports/daily.csv', $contents);

DB::transaction(function () use ($staged): void {
    // Update application records on the package's configured metadata connection.
    $version = $staged->publish(); // cleanup defaults to false
});
```

For directories, use `VersionedStorage::stageDirectory($path, $callback)` with the same scoped filesystem callback as `directory()`. Both staging APIs return a `StagedVersion` with a public `version` UUID, `publish(bool $cleanup = false): string`, and `discard(): void`. Ordinary `put()` and directory writes still upload and publish in one call when no metadata transaction is open.

Staging commits a durable snapshot stage before writing. Each filesystem mutation then commits its own short-lived operation journal before accessing storage: write/write stream, directory creation (including the initial snapshot directory), copy, move, deletion, and visibility changes. After confirmed success, the parent deletes that operation's journal before the next operation starts. Sequential callbacks therefore normally have at most one outstanding mutation journal. Reads, listings, and metadata queries do not create mutation journals.

The directory callback and all package database work stay in the calling process. Only filesystem operations run in supervised children where the execution policy requires them. Database transactions are short and do not span storage I/O. Each operation checks and locks the stage before starting and when acknowledging completion; cleanup claims the stage under the same lock, preventing subsequent operations from writing into a reclaimed snapshot. No response-termination hook or second metadata connection is required.

A stage becomes ready only after the callback returns successfully and all operations have succeeded. Any filesystem operation failure poisons the entire snapshot, even if the callback catches the exception: subsequent operations fail and the stage cannot be published. Files from earlier successful operations are not partial published versions. The existing current version remains unchanged.

Explicitly staged, completed snapshots have **no automatic expiry**. They remain hidden from reads/lists until publication. Publication locks the stage and resource and commits the version, current pointer, retirement flags, and published-stage state together. Outer or nested rollback restores the completed stage without deleting its files; publish it again or call `$staged->discard()` after the transaction ends. Failed callbacks with no outstanding operation may attempt immediate removal; uncertain mutations retain their journal and defer snapshot deletion until safe recovery. Failed removals remain discoverable for command retries without relying on process memory.

For queue jobs and other processes, pass the UUID and reload with `VersionedStorage::staged($uuid)`. Configure the same metadata database and named disk. Do not serialize a live stage/handler or transaction. Retrying publication of an already-published stage returns its UUID without changing the current version or applying cleanup again, even if a later version has retired it. Published-stage records and UUID reservations are retained for this idempotency guarantee. Discarding a published stage is rejected; discarding an unpublished stage prevents later publication and retains retry work if filesystem removal fails.

MySQL/InnoDB and PostgreSQL use row locks for journal transitions; recovery skips staging rows locked by metadata work (`SKIP LOCKED`, requiring support in the database server, such as MySQL 8+). SQLite serializes metadata writes with database-wide write locks. Outstanding operation deadlines protect storage work between these transactions. Keep application publication transactions short.

Recovery requires the metadata database and target disk to be available. The timeout and storage-completion assumptions below are part of its operating contract, not a universal remote-storage fencing guarantee. Completed stages deliberately abandoned by callers, or completed just before a caller crashes without retaining their UUID, still require explicit discard; ready does not mean abandoned.

### Upload Execution Limits and Delayed Recovery

Publish the updated config and migrations when upgrading. The config has two durations, in seconds:

```php
'upload_timeout' => 60,
'cleanup_grace' => 10800, // Three hours for accepted remote writes to finish.
```

Execution is selected by PHP SAPI and active queue context, not `APP_ENV`:

| Context | Execution limit |
| --- | --- |
| PHP-FPM (`fpm-fcgi`) | `upload_timeout`, enforced externally by PHP-FPM. |
| Active daemon queue job (`queue:work` / `horizon:work`) | Job `$timeout`, otherwise the current worker's `--timeout` (Laravel default: 60). Uses the existing worker, without forking. |
| CLI, including commands outside jobs | A supervised child per filesystem operation, each with a fresh `upload_timeout`. |
| `php artisan serve` (`cli-server`) | The same per-operation supervised fork mode as CLI. |
| `queue:work --once` | A supervised child per operation using the job/worker timeout; Laravel does not arm its daemon alarm in this mode. |

For FPM, set `upload_timeout` to the actual hard request execution limit. With Forge, align it with the configured maximum execution time and verify all three settings: PHP `max_execution_time`, FPM `request_terminate_timeout`, and Nginx `fastcgi_read_timeout`. **Only FPM's termination limit guarantees worker termination during blocked I/O; PHP's limit and Nginx's response timeout alone do not.** The package does not infer a hard deadline from `ini_get('max_execution_time')`, nor change server configuration. Configure FPM so requests cannot continue indefinitely after the response finishes.

Queue workers must have working PCNTL/POSIX timeout enforcement, working failure handlers, and normal Laravel process supervision. Keep `retry_after` (or the queue provider's visibility timeout) longer than the worker/job timeout. Zero/unbounded limits, unknown job-worker contexts, and unsupported web SAPIs fail explicitly rather than creating unsafe uploads. Synchronous jobs inherit the enclosing real job's limit, or the ordinary runtime policy outside a worker.

Fork mode requires Unix, PCNTL, POSIX, and sockets; native Windows is unsupported. In-memory SQLite metadata is supported because children perform no package database work. All resolved database connections must be outside transactions before starting a forked upload. The parent retains its connections; each child reconstructs the disk from its saved configuration. Configure custom drivers through the application and provide reproducible disk configuration. Do not use database connections or inherited remote clients inside custom filesystem drivers.

The callback runs in the parent, so its assertions and captured-variable changes are visible there. Each filesystem call gets an independent timeout: two successful writes may together take longer than `upload_timeout`. Reads, existence checks, metadata, and complete listing enumeration inside the callback are also supervised. Forked `readStream()` fully copies the remote stream into a temporary local stream before returning it, bounding remote reading at the cost of temporary disk space. Listings are materialized before leaving the child. Pure local path/URL construction and code outside filesystem calls do not get a child timeout. Database commits and publication are never covered by the child alarm. FPM request and daemon job limits still apply to the entire request/job and do not reset per operation.

Spatie Fork handles parent SIGINT/SIGTERM/SIGQUIT by terminating children. Each upload child also arms its own OS alarm, so a parent killed with SIGKILL does not leave an indefinitely running upload child; the child still has its original remaining time limit. This cannot guarantee instant termination during uninterruptible kernel I/O or bound work already accepted by a remote service.

Each mutation journal stores its own fixed deadline: **operation start + effective execution timeout + `cleanup_grace`**, rounded up to the next whole second so database timestamp precision never shortens it. Forked operations add one further second to cover whole-second alarm rounding. Successful operations delete their journal; failed or uncertain operations retain it, including when storage succeeds but database acknowledgement fails. Starting another operation never resets an outstanding journal.

The snapshot stage also tracks its latest recovery deadline, extended before each operation and never shortened. Cleanup claims an incomplete stage only after that deadline and all outstanding mutation deadlines, then removes the entire unpublished snapshot, including earlier successful files. A callback paused between operations may lose its stage to cleanup; subsequent filesystem calls and publication fail explicitly. Incomplete stages cannot be explicitly discarded early to bypass this safeguard. Ready stages can be discarded immediately and never expire automatically. Retained callback filesystem objects reject further operations after the callback ends; direct raw-adapter access is outside this guarantee.

Choose `cleanup_grace` to exceed the longest possible remote completion delay after writer termination. Three hours is a conservative default, not a proven upper bound for every driver/provider. Writes finishing beyond that contract, external/deferred writers, broken worker limits, and host-level failures are not universally fenced by this package. Publish and run the operation-journal migration when upgrading, with writers and cleanup paused and old workers drained; do not mix the former whole-upload execution path with per-operation writers during deployment. Existing incomplete stages retain their previous recovery deadlines.

Exceptions thrown after a database commit propagate without deleting the committed snapshot. If a failed package-managed commit leaves PDO in a transaction despite Laravel reporting transaction level zero, the package rolls back that transaction before deciding whether publication succeeded.

If a transaction-beginning listener throws during a package operation, the newly opened transaction or savepoint is rolled back to the operation's entry depth. An existing outer transaction and its earlier writes are preserved.

`get()` and `exists()` acquire a database resource lock before selecting a version and hold it through the filesystem operation. This protects both current-version and explicit-version reads that acquire the lock before cleanup. A read that starts after the version has been retired still reports it as missing. Each directory child read is protected separately, not as one multi-call read session.

This protection intentionally allows a slow read to delay publication and cleanup of the same resource. Reads are serialized per resource; SQLite uses its database-wide write lock because it does not support row locks. Inside an existing application transaction, locks remain held until that transaction finishes, so keep transactions short and acquire resources in a consistent order to avoid deadlocks.

Transaction handling relies on Laravel's connection APIs; do not bypass them with raw PDO commits/rollbacks. Database and filesystem operations are not a distributed transaction: process termination can leave staged files temporarily, but committed staging intents allow subsequent recovery. There are no package rollback/termination listeners responsible for physical deletion.

Version metadata and cleanup discovery use the primary database connection rather than a potentially lagging read replica. Handlers and live stage objects retain their original metadata connection and filesystem configuration even if configuration or container bindings change. Reloaded stages and the cleanup command resolve disks from their own application's configuration.

### Configuration and Facade

By default, the package uses the application's default filesystem disk and database connection. The published `config/laravel-versioned-storage.php` contains:

```php
return [
    'disk' => null,
    'connection' => null,
    'upload_timeout' => 60,
    'cleanup_grace' => 10800,
];
```

Set `disk` or `connection` to select named Laravel connections. Configure the database connection before running the package migration. You can also override the filesystem per call:

```php
$version = VersionedStorage::put('reports/daily.csv', 'contents', disk: 's3');
$contents = VersionedStorage::get('reports/daily.csv', $version, disk: 's3');
```

The same API is available through `SkyRaptor\LaravelVersionedStorage\Facades\VersionedStorage` and Laravel's auto-discovered `VersionedStorage` alias.

## Testing in Laravel Applications

For application tests, explicitly run uploads in the current process with `VersionedStorageTesting::runUploadsSynchronously()`. This works with in-memory SQLite, `Storage::fake()`, runtime-registered disks, mocks, and assertions inside upload callbacks. It uses the real metadata, versioning, publication, and cleanup implementation, not a separate fake storage implementation.

After publishing the package migrations, a Pest feature test can use:

```php
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\Testing\VersionedStorageTesting;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

uses(DatabaseMigrations::class);

beforeEach(function () {
    Storage::fake('exports');
    VersionedStorageTesting::runUploadsSynchronously();
});

it('stores a versioned report', function () {
    $version = VersionedStorage::put('reports/daily.csv', 'contents', disk: 'exports');

    expect(VersionedStorage::get('reports/daily.csv', disk: 'exports'))->toBe('contents');
    Storage::disk('exports')->assertExists("reports/daily.csv/{$version}");
});
```

In PHPUnit, call the helper from `setUp()` after `parent::setUp()`. The helper requires Laravel's testing environment and replaces only the upload execution policy in the current application container. Enable it for each fresh test application; it is never enabled automatically. Call `VersionedStorageTesting::restoreUploadExecution()` to restore the normal runtime policy in the same application.

**Synchronous testing does not enforce a hard upload timeout.** Configured timeout/grace values still determine journal recovery deadlines, and invalid values are still rejected. Use real-process integration tests to verify process termination and server limits; do not enable this helper in those processes.

Uploads still reject active metadata transactions. For tests exercising real uploads, use `DatabaseMigrations` or another database setup without an enclosing transaction, rather than `DatabaseTransactions` or transactional `RefreshDatabase`. Ensure no base test class wraps these tests in a transaction. Publishing an already-completed stage inside a transaction remains supported.

The helper does not change Laravel's cleanup concurrency driver. If a test runs the cleanup command against fake disks or in-memory SQLite, explicitly set `config(['concurrency.default' => 'sync'])` in that test so deletion tasks use the same process.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Laravel Versioned Storage! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Patrick Henninger](https://github.com/skyraptor)
- [All Contributors](../../contributors)

## License

Laravel Versioned Storage is licensed under [AGPL-3.0-only](LICENSE.md) with
the [Library Integration Exception](LICENSE-EXCEPTION.md). The complete AGPL
text is included in [LICENSE.md](LICENSE.md). Composer metadata identifies the base
AGPL license; the custom exception is described in the accompanying document.

Independently written applications, wrappers, and integrations may keep their
own license, including proprietary terms, and may bundle the package in a
vendor directory, Docker image, or appliance. The exception does not exempt
the package or its covered modifications from applicable AGPL source,
notice, and redistribution requirements, including network-source obligations.
Copying implementation into an application does not make it independent.

Separately licensed third-party material retains its applicable terms and notices.
