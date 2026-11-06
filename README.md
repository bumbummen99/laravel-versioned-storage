# Laravel Versioned Storage

<p align="center">
    <a href="https://packagist.org/packages/skyraptor/laravel-versioned-storage"><img src="https://img.shields.io/packagist/v/skyraptor/laravel-versioned-storage.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/skyraptor/laravel-versioned-storage"><img src="https://img.shields.io/packagist/php-v/skyraptor/laravel-versioned-storage.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://badge.laravel.cloud/badge/skyraptor/laravel-versioned-storage?style=flat"><img src="https://badge.laravel.cloud/badge/skyraptor/laravel-versioned-storage?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/bumbummen99/laravel-versioned-storage/actions"><img alt="GitHub Workflow Status (master)" src="https://img.shields.io/github/actions/workflow/status/bumbummen99/laravel-versioned-storage/tests.yml?branch=master&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/skyraptor/laravel-versioned-storage"><img src="https://img.shields.io/packagist/dt/skyraptor/laravel-versioned-storage.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Store versioned files and directory snapshots on Laravel filesystem disks.
Each write gets a unique path; the database tracks historical versions and
which version is current. Failed uploads cannot publish a partial snapshot.

## Installation

Requires **PHP 8.3+, Laravel 12 or 13**, and a Unix-like runtime with PCNTL,
POSIX, and sockets. Native Windows PHP is unsupported; use WSL2 or a Linux
container. Web uploads require PHP-FPM with a hard request termination limit.

```bash
composer require skyraptor/laravel-versioned-storage
php artisan vendor:publish --tag="laravel-versioned-storage"
php artisan migrate
```

The publish tag includes configuration and one initial-schema migration. To
publish only the required migration, use `laravel-versioned-storage-migrations`;
configuration is optional and uses `laravel-versioned-storage-config`.

## Usage

### Files

```php
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

$first = VersionedStorage::put('reports/daily.csv', 'first contents');
$second = VersionedStorage::put('reports/daily.csv', 'second contents');

VersionedStorage::get('reports/daily.csv');         // second contents
VersionedStorage::get('reports/daily.csv', $first); // first contents
VersionedStorage::exists('reports/daily.csv', $first);
VersionedStorage::versions('reports/daily.csv');   // [$first, $second]
```

`put()` accepts strings, streams, and Laravel `File`/`UploadedFile` objects.
File versions are stored at `<path>/<version>`, not at the logical path.
Read through this API rather than `Storage::get($logicalPath)`.

### Directory snapshots

```php
use Illuminate\Filesystem\FilesystemAdapter;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

$version = VersionedStorage::directory('reports/monthly', function (FilesystemAdapter $files): void {
    $files->put('summary.txt', 'Monthly summary');
    $files->put('details/data.csv', 'Monthly details');
});

$directory = VersionedStorage::directory('reports/monthly');
$directory->get('summary.txt');           // current snapshot
$directory->get('summary.txt', $version); // selected snapshot
$directory->exists('summary.txt', $version);
$directory->versions();                  // oldest to newest
```

Files live at `<directory>/<version>/<file>`. Each snapshot is independent:
previous files are not copied. Write all contents before the callback returns.
Paths are relative to the disk root/prefix; leading slashes are normalized.

### Cleanup

Pass `cleanup: true` to retire previous versions:

```php
VersionedStorage::put('reports/daily.csv', 'replacement', cleanup: true);
```

Retired versions disappear from public reads and lists immediately, but their
files remain until cleanup. Run or schedule:

```bash
php artisan laravel-versioned-storage:cleanup
```

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('laravel-versioned-storage:cleanup')
    ->everyFiveMinutes()
    ->withoutOverlapping();
```

Ensure Laravel's scheduler is running. Cleanup retries interrupted uploads
and failed deletions; it returns a failure exit code if any operation fails.
`--chunk=20` changes the default concurrency batch size of 10.
For synchronous, resource-specific cleanup, use `VersionedStorage::cleanup($path)`
or `$directory->cleanup()`.

### Transactions

Uploads must run **outside metadata transactions**. Stage first, then publish
on the same database connection as your application changes:

```php
use Illuminate\Support\Facades\DB;

$stage = VersionedStorage::stage('reports/daily.csv', 'contents');

DB::transaction(function () use ($stage): void {
    // Update application records here.
    $stage->publish(cleanup: true);
});
```

Use `stageDirectory($path, $callback)` for directories. Completed stages never
expire automatically: publish or explicitly `discard()` them outside a
transaction. After rollback, republish or discard. Across jobs/processes, pass
`$stage->version` and reload with `VersionedStorage::staged($uuid)`.

## Configuration

Defaults in `config/laravel-versioned-storage.php`:

```php
'disk' => null,             // Laravel's default filesystem disk
'connection' => null,       // Laravel's default database connection
'upload_timeout' => 60,     // seconds
'cleanup_grace' => 10800,   // remote-write completion allowance
```

Configure the metadata connection before migrating. Override the disk per call
with `disk: 's3'`. The same API is available through
`SkyRaptor\LaravelVersionedStorage\Facades\VersionedStorage`.

## Safety and constraints

- **Set real execution limits.** Align `upload_timeout` with FPM's
  `request_terminate_timeout`. PHP `max_execution_time` and Nginx
  `fastcgi_read_timeout` alone do not terminate workers blocked in I/O.
  Prevent FPM work from continuing indefinitely after a response.
- CLI/development-server uploads use supervised filesystem-operation forks.
  Daemon queue jobs use the job/worker timeout; `queue:work --once` uses forks.
  Queue limits must be positive and enforced; keep `retry_after`/visibility
  longer than the job timeout. Arbitrary callback code and database work are
  not covered by a filesystem-operation alarm.
- **Own each version container exclusively.** Do not share it across resources,
  disk aliases, or file/directory APIs, nest other resources inside it, or
  modify published snapshots externally. Overlap guards cannot detect every
  physical overlap across drivers.
- `cleanup_grace` must exceed the provider's longest possible completion delay
  after writer termination. Database/storage recovery must be available.
  This is not universal fencing against remote writes, database loss, or host
  failures; uncertain uploads remain until their recovery deadlines.
- Forks require no active transactions on resolved database connections and
  reproducible disk/custom-driver configuration. Cleanup process workers need
  identically configured named disks. Runtime-only disks require explicit
  same-process cleanup (`concurrency.default = sync`).
- Reads hold database locks and can delay publication/cleanup. Keep transactions
  short; SQLite serializes metadata writes. Recovery requires `SKIP LOCKED`
  support on row-locking databases, such as MySQL 8+.
- Failures throw, even on disks with `throw=false`. Missing versions return
  `false` from `exists()` and throw from `get()`. Download URLs are not protected
  from later cleanup; temporary upload URLs are unsupported. Forked stream
  reads buffer locally, so allow temporary disk space.
- The initial migration replaces the unreleased migration history, not existing
  schemas. Earlier development installations need a backed-up schema rebuild
  with writers/cleanup paused and old workers drained.

## Testing in Laravel Applications

Migrate the package tables and enable synchronous uploads in test setup:

```php
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\Testing\VersionedStorageTesting;

Storage::fake('local');
VersionedStorageTesting::runUploadsSynchronously();
config(['concurrency.default' => 'sync']); // when testing cleanup with fake disks
```

Use `DatabaseMigrations`, not a setup wrapping uploads in database transactions.
Enable the helper for each fresh application. It uses real package behavior but
does **not** enforce hard timeouts; keep real-process timeout tests separate.

## More

- [Contributing and package tests](.github/CONTRIBUTING.md)
- [Changelog](CHANGELOG.md)
- [Report a vulnerability](.github/SECURITY.md)
- [Laravel Boost integration skill](resources/boost/skills/laravel-versioned-storage-development/SKILL.md)

## License

[AGPL-3.0-only](LICENSE.md) with the [Library Integration Exception](COPYING.EXCEPTION).
Independent applications may retain their own license, including proprietary
terms; the package and covered modifications retain applicable AGPL obligations.
See [COPYING](COPYING) for the full license.

By [Patrick Henninger](https://github.com/skyraptor) and contributors.
