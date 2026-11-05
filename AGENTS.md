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

## Testing Guardrails

See [CONTRIBUTING.md](.github/CONTRIBUTING.md) for contributor setup and test commands. Preserve these less obvious boundaries when changing tests:

- `composer test:unit` runs every Pest suite in parallel. For focused runs, invoke Pest directly; parallel runs accept only one positional test path.
- Synchronous uploads prove package behavior, not process termination. Keep real subprocess and FPM coverage for timeout, crash recovery, and concurrency guarantees. A skipped FPM test is not validation of that behavior.
- Use [ProcessWait](tests/Support/ProcessWait.php) for bounded monotonic waits. Readiness may return early; absence-of-late-write observations must run for the entire interval, past the fixture's attempted write. Do not shorten them merely to speed up tests.
- Use [SubprocessFixture](tests/Support/SubprocessFixture.php) for owned subprocesses and isolated SQLite databases. Give it an unresolved connection name; do not purge an existing application connection to bypass its ownership guard.
- Teardown must attempt to stop every owned process and report failures. Do not delete resources while a process may still use them; preserve the retention behavior in [ProcessCleanup](tests/Support/ProcessCleanup.php).
- Prefer [TestUploadClock](tests/Support/TestUploadClock.php) for deterministic deadline tests rather than global Carbon time changes. Real-process tests still need real elapsed time.
- Crash hooks must target the intended durability boundary. Replacing a SQL-sensitive hook with an earlier transaction event can silently change what the test proves.
- FPM startup retries are for confirmed address-in-use collisions only. Do not turn unrelated HTTP failures or process exits into retries.

## Safety and Documentation Boundaries

- Upload callbacks and database work stay in the parent process; only individual filesystem operations are supervised in children. Do not describe the operation timeout as a bound on the entire callback.
- Missing journal acknowledgments or absent files do not prove a writer has stopped. Preserve the recovery deadline and uncertain-state retention before allowing cleanup.
- Keep contributor documentation focused on practical usage. Put concise agent-specific pitfalls here, implementation rationale near the relevant code, and consumer behavior in the README and bundled Boost skill. Do not add refactor histories, transient test counts, or benchmark sample counts to this file.

## Local Skills

- `package-bugfix`: use for supervised bug finding: reproduce one issue with a failing regression, stop for review, and fix it only after explicit approval. Reuse the specialized skills below as applicable.
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