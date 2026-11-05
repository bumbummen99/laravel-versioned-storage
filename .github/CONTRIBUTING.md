# Contribution Guide

Thank you for considering contributing to Laravel Versioned Storage! Please review the following guidelines before submitting a pull request.

For significant changes, please open an issue first so we can discuss the approach.

## Process

1. Fork the project
2. Create a new branch
3. Code, test, commit, and push
4. Open a pull request detailing your changes

## Guidelines

- Ensure the coding style passes by running `composer lint`.
- Send a coherent commit history, making sure each commit in your pull request is meaningful.
- You may need to [rebase](https://git-scm.com/book/en/v2/Git-Branching-Rebasing) to avoid merge conflicts.
- Please remember that we follow [SemVer](http://semver.org/).

## Setup

Use a Unix-like PHP runtime with PCNTL, POSIX, and sockets. Native Windows PHP is unsupported; Windows hosts can use WSL2 or a Linux container. CI runs on Ubuntu and macOS across the supported PHP/Laravel versions and both dependency stability lanes.

Clone your fork, then install the dev dependencies:

```bash
composer install
```

## Lint

Lint your code:

```bash
composer lint
```

## Tests

Run all tests:

```bash
composer test
```

### Test Categories

- `tests/Unit`: isolated class/function behavior using PHPUnit's base test case. Do not boot Laravel or use its container, facades, database, or configured disks. Mock collaborators where appropriate.
- `tests/Feature`: Laravel integration tests using Orchestra Testbench, including provider/container wiring, commands, storage, database transactions, and cross-process reader locking.
- `tests/Specification`: consumer-facing acceptance tests defining the package's public API. These also use Testbench but remain a separate suite to distinguish the reference contract from implementation regressions.
- `tests/ArchTest.php`: architecture constraints, separate from runtime integration tests.
- `tests/Support`: fixtures and subprocess helpers, not a test suite.

Prefer public behavior assertions (contents, publication, preserved files, and
retryable failures) over exact query or adapter-call counts. Keep exact counts
only in tests specifically covering delegation or the cleanup query budget.
FTP prefix tests validate each requested path without prescribing how many
existence checks an implementation performs.

Filesystem execution and deadline calculation are internal testing seams:
`FilesystemOperationExecutor` is resolved through the application container,
while `UploadDeadline` uses an injectable `UploadClock` for wall and monotonic
time. Execution overrides must not bypass parent-owned operation journals or
publication checks. Use deterministic clock tests for deadline arithmetic;
keep real fork/FPM tests for OS-enforced termination and stream transfer.
Neither seam is a replacement for real database or filesystem integration.

Subprocess fixtures should signal progress explicitly rather than recognizing
SQL strings. The reader-lock fixture uses Laravel's transaction-beginning
event to signal that it reached a transaction boundary, then verifies that
the worker stays blocked until the protected read completes. Keep this
implementation-sensitive synchronization isolated in the fixture.

Use `tests/Support/ProcessWait` for bounded subprocess readiness and blocking
observations. It uses monotonic time and includes process output and optional
fixture logs on failure. Readiness may finish early; observations must hold
for their full duration. Do not replace real upload timeout thresholds with
generous fixture readiness budgets.

`SubprocessFixture` owns an isolated SQLite database and any processes started
through it. Always close it in `finally`, restoring configuration and stopping
processes before deleting its temporary directory. Server-specific resources
remain owned by the server fixture. `TestUploadClock` provides independent
wall and monotonic time without changing Carbon's global test clock.

Run all Pest tests without the additional quality checks:

```bash
composer test:unit
```

`composer test` runs static analysis, formatting checks, type coverage, and all Pest suites. `composer test:unit` runs all Pest suites without the additional quality checks.

### Real PHP-FPM timeout tests

Ordinary feature/specification tests explicitly use `VersionedStorageTesting::runUploadsSynchronously()` with real metadata and fake disks. Execution-policy tests restore the production policy. Per-operation tests verify committed mutation journals, parent-owned callbacks/database work, independent child timeouts, and recovery after failed acknowledgements. Real execution tests must not enable synchronous execution in their server/worker processes.

Upload execution tests use the Workbench application: CLI scenarios and real queue workers run through `vendor/bin/testbench`, while the development server and FPM share a minimal HTTP bootstrap into that same application. Test-only commands, routes, and configuration are enabled by `VERSIONED_STORAGE_TEST_DB` and require `VERSIONED_STORAGE_TEST_ROOT`; the tests supply isolated temporary resources. Normal Workbench sessions do not expose these scenarios, and the tests do not invoke `workbench:build` or its database reset steps.

Install PHP-FPM matching your CLI PHP version and Nginx (included in the devcontainer), then run:

```bash
vendor/bin/pest --group=fpm
```

These tests start isolated, unprivileged FPM and Nginx processes with temporary configuration, a file-backed SQLite database, and a local test disk. They do not modify system server configuration or use existing server instances. They are also included in the normal suite, but skip when either binary is unavailable or on Windows.

The scenarios exercise `max_execution_time` from a temporary `php.ini`, `request_terminate_timeout` from an FPM pool, and Nginx's `fastcgi_read_timeout`. A Workbench-only filesystem adapter simulates CPU-bound or blocked work inside the actual write, not the directory callback. The tests verify successful uploads, PHP termination of CPU-bound work, FPM termination during blocked work, and that an Nginx 504 does not terminate an upload. Incomplete journals and partial files survive until the persisted cleanup deadline, then cleanup removes both; successful uploads remain readable. The Nginx-only scenario deliberately disables FPM termination to demonstrate that PHP and Nginx limits alone do not provide the required blocked-I/O bound.

## Benchmarks

Compare the previous commit (`HEAD^`) against the current working tree, including uncommitted changes:

```bash
composer benchmark
```

The command creates a temporary baseline worktree, benchmarks both versions using the current harness and dependencies, prints the comparison, and removes the worktree even if benchmarking fails. Results remain under the ignored `build/phpbench` directory. Set `VERSIONED_STORAGE_BENCHMARK_BASE` to choose another baseline, such as `HEAD` to measure only uncommitted changes. PHPBench options are forwarded to both runs, for example `composer benchmark -- --filter=benchReadFile`.

The benchmarks use a temporary file-backed SQLite database and an isolated, named local disk. Each iteration starts with fresh fixtures; application boot, migrations, fixture creation, and teardown are outside the timed operation. Subjects cover file reads, existence checks, version listing, new resource registration, file/directory writes, directory child reads, and replacements with cleanup. They exercise the real CLI execution policy, including supervised upload forks on revisions that support them, and therefore require Unix with PCNTL, POSIX, and sockets. They measure local package and CLI supervision overhead, not PHP-FPM throughput or remote-server latency. Peak memory includes the bootstrapped application, not aggregate child-process memory.

Replacement-with-cleanup subjects explicitly call the synchronous cleanup API after publication so they include actual deletion costs, not just retirement flags. Other writes do not automatically delete retired snapshots.

Each scenario uses 10 iterations, with repetitions tuned to its workload:

| Scenarios | Measured operations per iteration | Warmup operations per iteration |
| --- | ---: | ---: |
| Reads, existence checks, version listing | 1,000 | 20 |
| Replacement with cleanup (file and directory) | 100 | 10 |
| Writes without cleanup and resource registration | 10 | 2 |

This uses 180 measurement subprocesses across nine scenarios and both revisions, down from 900 at 50 iterations. Longer read and cleanup samples amortize setup overhead and reduce sensitivity to brief interruptions. Cleanup keeps one current version between operations; growing write and registration workloads retain their previous sample size so larger repetition counts do not change their scale. Those short write samples remain more sensitive to noise. The full comparison measures 84,600 operations; operation counts differ from earlier harness settings, so regenerate both baseline and current measurements rather than comparing against old stored runs.

The `benchmarks` pull-request workflow automatically compares the PR target SHA (`pull_request.base.sha`) with the PR head SHA (`pull_request.head.sha`), not GitHub's synthetic merge commit. Both runs use the head revision's benchmark harness and installed dependencies on the same runner, but load package classes and migrations from their respective checkouts. This isolates package-code changes; it does not compare separate dependency upgrades. Benchmark failures fail the job, while timing differences are informational rather than pass/fail thresholds. Open the **compare** job's summary from the PR's benchmarks check to see the measurements and differences.

PHPBench uses repeated iterations and warmups, but local and shared-runner timing still varies; small differences are not evidence of a regression.

The configured remote executor uses a project-local template with PHP's monotonic `hrtime()` clock instead of wall-clock time, so clock corrections cannot produce negative durations. Results are converted from nanoseconds to PHPBench's microsecond units. Process isolation, lifecycle hooks, warmups, and memory reporting remain enabled for both revisions.
