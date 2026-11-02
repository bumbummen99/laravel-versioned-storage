# Laravel Versioned Storage

This repository is a Laravel package. Keep the package focused, idiomatic, and easy for Laravel developers to install, test, and maintain.

## Package Conventions

- Use Laravel-native package APIs and the existing service provider shape before adding abstractions.
- Keep package names, namespaces, Composer metadata, publish tags, documentation, and examples aligned with `skyraptor/laravel-versioned-storage`.
- Add only the files and dependencies needed for the package behavior being implemented.
- Prefer explicit Laravel package code over helper abstractions unless the extension point is real.
- Keep tests focused on observable package behavior through public APIs, service provider wiring, commands, routes, published resources, and documentation promises.

## Quick Commands

- Full validation: `composer test`
- Formatting check: `composer lint:check`
- Static analysis: `composer analyse`
- Pest tests: `composer test:unit`
- Workbench build: `composer build`
- Workbench server: `composer serve`

## Local Skills

- `package-scaffold`: use when adding package capabilities or wiring them through the service provider, including commands, migrations, routes, config, views, translations, assets, middleware, publish tags, workbench files, and console-only behavior.
- `package-testing`: use when adding or changing package tests with Pest 4/5 and Orchestra Testbench.
- `package-release`: use when preparing changelog, release notes, tags, or GitHub release workflow changes.
- `package-compatibility`: use when reviewing code, dependencies, or CI against the PHP and Laravel support matrix.
- `package-generate-skill`: use when updating the bundled Boost skill from the package implementation, README, and examples.

## Project Description
Laravel Versioned Storage is a small package, that provides simple, non interfering write operations for one or many files on any supported filesystem driver.

It primarily handles the storage of files in a versioned manner, where writing the file or directory multiple times is a new version. This is very important when 
write operations might be run from different processes, where writing to the same destination might interfere, even with locks, since the driver itself might stall, 
creating a race condition. Versions and the reference to the current version are stored in the Database to provide a common storage between installations that
can be changed transactionally.

### File
The parent directory of the file path contains version directories, which contain the file. So the file path `foo/bar/baz` becomes `foo/bar/baz/<version>`.

### Directory
The directory path contains version directories, which contain the files. So the file `baz` in the directory `foo/bar` becomes `foo/bar/<version>/baz`.