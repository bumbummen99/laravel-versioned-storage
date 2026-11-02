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

### 3. Apply the public API

Use `SkyRaptor\LaravelVersionedStorage\VersionedStorage` or `SkyRaptor\LaravelVersionedStorage\Facades\VersionedStorage`.

- `put($path, $contents, $cleanup = false, $disk = null)` returns a version string.
- `get($path, $version = null, $disk = null)` reads the selected or current version.
- `exists($path, $version = null, $disk = null)` checks a selected or current file.
- `versions($path, $disk = null)` lists versions oldest first.
- `directory($path, $callback, $cleanup = false, $disk = null)` writes a new independent snapshot and returns its version.
- `directory($path)` returns a handler with `get($file, $version = null)`, `exists($file, $version = null)`, and `versions()`.

The directory callback receives a scoped Laravel `FilesystemAdapter`. All files in the new snapshot must be written by that callback; previous files are not copied.

Files use `<path>/<version>`; directory children use `<directory>/<version>/<child>`. Read through this API rather than through `Storage::get($logicalPath)`.

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
- Do not use `cleanup: true` if consumers still need old versions or may be reading them.
- Do not assume database rollback undoes filesystem changes.
- Do not swallow callback/write failures: incomplete versions must not become current.
- Do not depend on private metadata schema or mutate published snapshots directly.
