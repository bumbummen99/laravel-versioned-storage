# Contribution Guide

Thank you for contributing to Laravel Versioned Storage. For significant
changes, please open an issue first to discuss the behavior and approach.

## Getting Started

Use PHP 8.3+ on a Unix-like runtime with PCNTL, POSIX, and sockets.
Native Windows PHP is unsupported; use WSL2 or a Linux container instead.
See the [README](../README.md) for package requirements and supported behavior.

1. Fork the repository and create a branch.
2. Install dependencies with `composer install`.
3. Make a focused change with relevant tests and documentation.
4. Run `composer test` before opening a pull request.
5. Explain the change, its purpose, and any compatibility or behavior changes
   in the pull request. Include validation results and any tests you could
   not run.

Keep commits coherent and avoid unrelated changes. The package follows
[Semantic Versioning](https://semver.org/).

## Validation

Run the complete validation:

```bash
composer test
```

This runs static analysis, formatting checks, type coverage, and all Pest
suites. Individual checks are also available:

```bash
composer analyse
composer lint:check
composer test:types
composer test:unit
```

Despite its name, `composer test:unit` runs all Pest suites, not only unit
tests. To format code, use `composer lint`. For a focused test run:

```bash
vendor/bin/pest tests/Specification/Storage/FileTest.php
```

CI validates Ubuntu and macOS across the supported PHP/Laravel versions
with lowest and stable dependency combinations.

## Writing Tests

Choose the category that matches the behavior:

- `tests/Unit`: isolated class behavior without booting Laravel.
- `tests/Feature`: Laravel integration through Orchestra Testbench, including
  storage, database transactions, commands, and process behavior.
- `tests/Specification`: acceptance tests defining the public package contract.
- `tests/ArchTest.php`: architecture constraints.
- `tests/Support`: reusable fixtures and subprocess helpers, not a test suite.

Within each suite, tests are grouped by responsibility:

| Suite | Subdirectories |
| --- | --- |
| Specification | `Storage` (consumer file/directory examples), `Execution` (runtime and timeout guarantees) |
| Feature | `Storage`, `Filesystem`, `Drivers`, `Uploads`, `Transactions`, `Recovery`, `Package`, `TestSupport` |
| Unit | `Filesystem`, `Uploads`, `TestSupport` |

Use the existing group matching the behavior. For example, run all driver
integration tests with `vendor/bin/pest tests/Feature/Drivers`. Grouping is
for navigation; tests must remain independent of execution order.

Prefer observable behavior over internal query shapes or incidental call
counts. Use descriptive test names, explicit scenarios, distinct revision
contents, and complete expected results. Helpers should simplify setup
without hiding the behavior being asserted.

Ordinary application-backed tests use the synchronous upload helper with
real metadata and fake disks. See the [README testing section](../README.md#testing-in-laravel-applications).
Keep real process tests where OS termination, concurrency, or crash recovery
is the requirement; synchronous execution cannot prove those guarantees.

Reuse existing fixtures, bound waits, and clean up owned processes and
temporary resources in `finally`. Restore any global state you change.
Do not weaken timeout assertions or retry failures until they pass.

To investigate order-dependent failures, record the seed and use a randomized
run:

```bash
vendor/bin/pest --parallel --order-by=random --random-order-seed=6107
```

### Real PHP-FPM Tests

Install PHP-FPM matching your CLI PHP version and Nginx, then run:

```bash
vendor/bin/pest --group=fpm
```

These tests are included in the normal suite but skip when the required
binaries are unavailable or on Windows. They start isolated servers with
temporary configuration and do not modify system server settings.

Coverage includes PHP `max_execution_time`, FPM `request_terminate_timeout`,
and Nginx `fastcgi_read_timeout`, including the distinction between an HTTP
timeout and actual upload termination.

## Benchmarks

Compare the previous commit (`HEAD^`) with the current working tree:

```bash
composer benchmark
```

Set `VERSIONED_STORAGE_BENCHMARK_BASE` to choose another baseline, such as
`HEAD` for uncommitted changes. PHPBench options are forwarded to both runs:

```bash
VERSIONED_STORAGE_BENCHMARK_BASE=HEAD composer benchmark -- --filter=benchReadFile
```

Results are stored under the ignored `build/phpbench` directory. The harness
uses isolated local storage and SQLite, with fixture setup outside timed
operations. It measures package and CLI supervision overhead, not remote
storage latency or PHP-FPM throughput. Cleanup scenarios include physical
deletion; peak memory does not include aggregate child-process memory.

Pull-request benchmarks compare the PR base and head using the same harness
and dependencies. See the benchmark workflow's **compare** job summary for
results. Small timing differences on shared runners are not proof of a
regression; compare repeated measurements under equivalent conditions.
