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

### Cleanup and Errors

Pass `cleanup: true` to `put()` or `directory()` to remove all previously published versions of that resource, both from the database and from the filesystem. Other resources and unregistered files are left untouched.

Writes complete before the current-version pointer is changed in a database transaction. Concurrent publications of the same resource are serialized through a persistent database row; filesystem writes themselves do not hold that lock. Failed writes or callbacks are not published, and their staged data is removed. Missing versions return `false` from `exists()` and raise an exception from `get()`. Filesystem and database failures also raise exceptions.

Cleanup runs after publication. If deleting an old version fails, an exception is raised but the new version remains current. Database transactions cannot roll back filesystem operations. Retain old versions when consumers may still be reading them; immediate cleanup can invalidate in-flight reads of a previous version.

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
