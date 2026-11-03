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

Run all Pest tests without the additional quality checks:

```bash
composer test:unit
```

`composer test` runs static analysis, formatting checks, type coverage, and all Pest suites. `composer test:unit` runs all Pest suites without the additional quality checks.

## Benchmarks

Run the local PHPBench scenarios:

```bash
composer benchmark
```

The benchmarks use an in-memory SQLite database and a local fake disk. Each iteration starts with fresh fixtures; application boot, migrations, fixture creation, and teardown are outside the timed operation. Subjects cover file reads, existence checks, version listing, new resource registration, file/directory writes, directory child reads, and replacements with cleanup. They measure local package overhead, not remote-server latency. Peak memory includes the bootstrapped application.

The `benchmarks` pull-request workflow automatically compares the PR target SHA (`pull_request.base.sha`) with the PR head SHA (`pull_request.head.sha`), not GitHub's synthetic merge commit. Both runs use the head revision's benchmark harness and installed dependencies on the same runner, but load package classes and migrations from their respective checkouts. This isolates package-code changes; it does not compare separate dependency upgrades. Benchmark failures fail the job, while timing differences are informational rather than pass/fail thresholds. Open the **compare** job's summary from the PR's benchmarks check to see the measurements and differences.

To compare two revisions locally without changing your working files:

```bash
git worktree add --detach build/benchmark-base HEAD^

VERSIONED_STORAGE_BENCHMARK_SOURCE="$PWD/build/benchmark-base" XDEBUG_MODE=off \
  vendor/bin/phpbench run --store --tag=local_base

XDEBUG_MODE=off vendor/bin/phpbench run --ref=local_base --report=aggregate

git worktree remove build/benchmark-base
```

Use the desired baseline revision in place of `HEAD^`. The second run measures the current working tree, including uncommitted changes. Results are stored under the ignored `build/phpbench` directory. PHPBench uses repeated iterations and warmups, but shared-runner timing still varies; small differences are not evidence of a regression. The deterministic SQL/filesystem call-budget tests continue running separately in `composer test`.
