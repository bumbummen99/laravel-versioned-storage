# Providing a safe way to write one or many files using Laravel's Storage without interfering with another context.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/skyraptor/laravel-versioned-storage.svg?style=flat-square)](https://packagist.org/packages/skyraptor/laravel-versioned-storage)
[![GitHub Tests Action Status](https://github.com/spatie/package-laravel-versioned-storage-laravel/actions/workflows/run-tests.yml/badge.svg)](https://github.com/skyraptor/laravel-versioned-storage/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://github.com/spatie/package-laravel-versioned-storage-laravel/actions/workflows/fix-php-code-style-issues.yml/badge.svg)](https://github.com/skyraptor/laravel-versioned-storage/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/skyraptor/laravel-versioned-storage.svg?style=flat-square)](https://packagist.org/packages/skyraptor/laravel-versioned-storage)

Laravel Versioned Storage writes each file or directory revision to a unique path and stores the current revision in the database. Consumers can continue reading a stable current version while a new version is being written. The database migration must be run before using the package.

## Support us

[<img src="https://spatie.be/github-ad-image/laravel-versioned-storage.jpg" width="419px" />](https://spatie.be/github-ad-click/laravel-versioned-storage)

We invest a lot of resources into creating [best in class open source packages](https://spatie.be/open-source). You can support us by [buying one of our paid products](https://spatie.be/open-source/support-us).

We highly appreciate you sending us a postcard from your hometown, mentioning which of our package(s) you are using. You'll find our address on [our contact page](https://spatie.be/about-us). We publish all received postcards on [our virtual postcard wall](https://spatie.be/open-source/postcards).

## Installation

You can install the package via composer:

```bash
composer require skyraptor/laravel-versioned-storage
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="laravel-versioned-storage-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="laravel-versioned-storage-config"
```

The optional config file lets you select the filesystem disk, database connection, and metadata table:

```php
return [
    'disk' => null, // Use the application's default filesystem disk.
    'database' => null, // Use the application's default database connection.
    'table' => 'versioned_storage_entry',
];
```

Optionally, you can publish the views using

```bash
php artisan vendor:publish --tag="laravel-versioned-storage-views"
```

## Usage

```php
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

$version = VersionedStorage::put('reports/daily.csv', $contents);
$contents = VersionedStorage::get('reports/daily.csv');
$oldContents = VersionedStorage::get('reports/daily.csv', $version);
$versions = VersionedStorage::versions('reports/daily.csv');

$directoryVersion = VersionedStorage::directory('reports/monthly', function ($files) {
    $files->put('summary.txt', 'Monthly summary');
});

$summary = VersionedStorage::directory('reports/monthly')->get('summary.txt', $directoryVersion);
```

Pass `true` as the cleanup argument to `put` or `directory` to remove previous versions. Without it, earlier versions remain available.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Patrick Henninger](https://github.com/bumbummen99)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
