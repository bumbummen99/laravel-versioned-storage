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

Compare the previous commit (`HEAD^`) against the current working tree, including uncommitted changes:

```bash
composer benchmark
```

The command creates a temporary baseline worktree, benchmarks both versions using the current harness and dependencies, prints the comparison, and removes the worktree even if benchmarking fails. Results remain under the ignored `build/phpbench` directory. Set `VERSIONED_STORAGE_BENCHMARK_BASE` to choose another baseline, such as `HEAD` to measure only uncommitted changes. PHPBench options are forwarded to both runs, for example `composer benchmark -- --filter=benchReadFile`.

The benchmarks use an in-memory SQLite database and a local fake disk. Each iteration starts with fresh fixtures; application boot, migrations, fixture creation, and teardown are outside the timed operation. Subjects cover file reads, existence checks, version listing, new resource registration, file/directory writes, directory child reads, and replacements with cleanup. They measure local package overhead, not remote-server latency. Peak memory includes the bootstrapped application.

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
