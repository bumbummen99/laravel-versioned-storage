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

Run individual categories or all Pest suites:

```bash
composer test:unit
composer test:feature
composer test:specification
composer test:pest
```

`composer test` runs static analysis, formatting checks, type coverage, and all Pest suites. `composer test:unit` runs only the Unit suite.
