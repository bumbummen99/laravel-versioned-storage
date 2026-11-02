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

Versioned, safe storage for Laravel.

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

<!-- Add a basic usage example here. -->

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
