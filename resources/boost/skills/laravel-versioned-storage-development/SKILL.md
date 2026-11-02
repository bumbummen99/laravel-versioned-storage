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
- `cleanup($path, $disk = null)` retries pending file-version deletions without creating a version.
- `directory($path, $callback, $cleanup = false, $disk = null)` writes a new independent snapshot and returns its version.
- `directory($path)` returns a handler with `get($file, $version = null)`, `exists($file, $version = null)`, `versions()`, and `cleanup()`.

The directory callback receives a scoped Laravel `FilesystemAdapter`. All files in the new snapshot must be written by that callback; previous files are not copied.

Use non-nested resource roots on each disk: `reports/daily` and `reports/monthly` can coexist, but neither can coexist with a `reports` root. Overlapping registrations throw before writing. UUIDs in logical paths and arbitrary nested callback contents remain allowed; file and directory resources at the exact same root remain distinct.

Scoped public and temporary download URLs retain the selected disk's URL behavior and target the specific snapshot. External URL reads are not protected against cleanup. Temporary upload URLs are rejected: `providesTemporaryUploadUrls()` returns `false` and `temporaryUploadUrl()` throws, even with custom upload URL builders. Write all snapshot contents before the callback returns.

Files use `<path>/<version>`; directory children use `<directory>/<version>/<child>`. Read through this API rather than through `Storage::get($logicalPath)`.

`put()` accepts strings, streams, and Laravel `File`/`UploadedFile` objects. Uploaded files are streamed into the exact version filename.

### 4. Coordinate cleanup and transactions

- Pass `cleanup: true` to retire previous versions. Failed deletions keep durable retry records; the new version remains current.
- Run or schedule `php artisan laravel-versioned-storage:cleanup` to retry pending deletions across resources. It fails explicitly if any deletion still fails.
- Laravel-managed outer transactions defer cleanup until the outermost commit. Rollback removes staged versions and preserves the previous version.
- A failing transaction-beginning listener rolls back only the package operation's newly opened transaction or savepoint, preserving existing outer transaction work.
- Current and explicit-version `get()`/`exists()` hold resource locks through the filesystem read. Cleanup waits for active reads; already retired versions are unavailable to new reads.
- Keep transactions short: locks acquired inside an outer transaction last until it finishes. SQLite uses database-wide write locking.
- Metadata reads use the primary database, not read replicas. Handlers and deferred transaction work retain their originally resolved filesystem instance.
- First registrations reserve roots under a per-disk database lock; failed writes can leave an empty reservation. Outer transactions retain registration locks until they finish.
- Duplicate version UUIDs and occupied destination paths fail before writes. Committed UUID reservations survive cleanup and failed writes; outer rollbacks undo uncommitted reservations. Publish/run the reservations migration with package writers paused when upgrading.
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
- Do not assume filesystem operations are crash-atomic with database commits; process termination before publication can leave staged files.
- Do not swallow callback/write failures: incomplete versions must not become current.
- Do not depend on private metadata schema or mutate published snapshots directly.
