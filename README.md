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

You can install the package via Composer:

```bash
composer require skyraptor/laravel-versioned-storage
```

You may publish all of the package's resources at once:

```bash
php artisan vendor:publish --tag="laravel-versioned-storage"
```

Or, you may publish each resource individually:

### Publishing the Configuration File

```bash
php artisan vendor:publish --tag="laravel-versioned-storage-config"
```

### Publishing and Running the Migrations

```bash
php artisan vendor:publish --tag="laravel-versioned-storage-migrations"
php artisan migrate
```

### Publishing the Views

```bash
php artisan vendor:publish --tag="laravel-versioned-storage-views"
```

### Publishing the Translations

```bash
php artisan vendor:publish --tag="laravel-versioned-storage-lang"
```

### Publishing the Public Assets

```bash
php artisan vendor:publish --tag="laravel-versioned-storage-assets"
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

The scoped filesystem preserves the selected disk's public and temporary download URL behavior, including custom URL builders. URLs target that specific snapshot; external URL requests do not acquire the package's read locks and can fail after cleanup removes the version. Temporary upload URLs are explicitly unsupported: `providesTemporaryUploadUrls()` returns `false`, and `temporaryUploadUrl()` throws a `RuntimeException`, even if the disk supports them or a custom upload URL builder is configured. All snapshot contents must be written before the callback returns; deferred uploads would allow writes after publication.

### Cleanup and Errors

Pass `cleanup: true` to `put()` or `directory()` to remove all previously published versions of that resource, both from the database and from the filesystem. Other resources and unregistered files are left untouched.

Writes complete before the current-version pointer is changed in a database transaction. Concurrent publications of the same resource are serialized through a persistent database row; filesystem writes themselves do not hold that lock. Failed writes or callbacks are not published, and their staged data is removed. Missing versions return `false` from `exists()` and raise an exception from `get()`. Filesystem and database failures also raise exceptions.

Cleanup runs after publication commits. If deleting an old version fails, an exception is raised but the new version remains current. Old versions are marked for deletion transactionally and hidden from public reads/lists; their database records are retained until physical deletion succeeds. Cleanup continues attempting other deletions when one fails. A subsequent write retries pending deletions, or you can retry without creating a new version:

```php
VersionedStorage::cleanup('reports/daily.csv');
VersionedStorage::directory('reports/monthly')->cleanup();
```

To retry pending deletions across all resources on the configured metadata connection, run or schedule:

```bash
php artisan laravel-versioned-storage:cleanup
```

The command returns a failure exit code if any deletion still fails. It also recovers committed cleanup work left by a process that stopped before deleting the files.

### Transactions and Concurrent Reads

Writes inside Laravel-managed transactions on the metadata connection are supported. Cleanup waits for the outermost commit, including nested savepoint commits. Rolling back removes newly staged files and restores the previous current version without deleting its contents. Exceptions thrown after the database commits are propagated, but do not cause the committed current version to be deleted. If an application after-commit callback prevents automatic cleanup from running, the durable pending-deletion records remain available to the retry command.

`get()` and `exists()` acquire a database resource lock before selecting a version and hold it through the filesystem operation. This protects both current-version and explicit-version reads that acquire the lock before cleanup. A read that starts after the version has been retired still reports it as missing. Each directory child read is protected separately, not as one multi-call read session.

This protection intentionally allows a slow read to delay publication and cleanup of the same resource. Reads are serialized per resource; SQLite uses its database-wide write lock because it does not support row locks. Inside an existing application transaction, locks remain held until that transaction finishes, so keep transactions short and acquire resources in a consistent order to avoid deadlocks.

Transaction handling relies on Laravel's connection APIs and transaction events; do not bypass them with raw PDO commits/rollbacks or remove the package's listeners. Database and filesystem operations are not a distributed transaction: process termination during an unpublished write can still leave staged files, and filesystem failures during rollback are reported rather than silently ignored.

Version metadata and cleanup discovery use the primary database connection rather than a potentially lagging read replica. Handlers retain the filesystem instance selected when they are created, so deferred commit/rollback work still targets the original disk if its container binding changes.

### Configuration and Facade

By default, the package uses the application's default filesystem disk and database connection. The published `config/laravel-versioned-storage.php` contains:

```php
return [
    'disk' => null,
    'connection' => null,
];
```

Set `disk` or `connection` to select named Laravel connections. Configure the database connection before running the package migration. You can also override the filesystem per call:

```php
$version = VersionedStorage::put('reports/daily.csv', 'contents', disk: 's3');
$contents = VersionedStorage::get('reports/daily.csv', $version, disk: 's3');
```

The same API is available through `SkyRaptor\LaravelVersionedStorage\Facades\VersionedStorage` and Laravel's auto-discovered `VersionedStorage` alias.

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

Laravel Versioned Storage is open-sourced software licensed under the [MIT license](LICENSE.md).
