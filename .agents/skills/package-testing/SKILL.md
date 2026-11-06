---
name: package-testing
description: "Use this skill when writing, editing, fixing, organizing, or reviewing package tests with Pest 4/5 and Orchestra Testbench, including consumer specifications, acceptance tests, TDD, feature tests, unit tests, type coverage, arch tests, workbench behavior, commands, routes, config, migrations, and publishable resources."
license: MIT
metadata:
  author: laravel
---

# Package Testing

## Primary Goal

Prove package behavior with Pest 4/5, Orchestra Testbench, and the local `tests/TestCase.php` setup before or alongside implementation.

## Workflow

1. Start with TDD: write the smallest failing package test for the requested behavior, then implement the smallest change that makes it pass.
2. Cover happy-path, unhappy-path, and edge-case behavior when the feature has meaningful failure modes.
3. Choose the test category by its purpose using the organization below, not merely whether it boots Laravel.
4. Use `composer test:unit -- --filter ...` while iterating, `composer test:types` when type-sensitive tests or code changed, and `composer test` before finishing.
5. Keep real package tests in the suite and remove only throwaway tests that were explicitly created for local scaffolding experiments.

## Mutation Testing

- `composer test:mutation` runs Pest's bundled mutation plugin in parallel over the whole package using `--everything` and the PHPUnit source scope, including classes without explicit mutation declarations. It is opt-in and not part of `composer test`.
- Requires PCOV or Xdebug 3 with coverage enabled; use `XDEBUG_MODE=coverage` when using Xdebug.
- Whole-package analysis is the default. For optional targeted investigations, invoke `vendor/bin/pest --mutate --parallel --class=...` directly. Do not add `--class` to the Composer command: Pest ignores class selection with `--everything`.
- Use `--no-cache` to measure fresh results and `--min=...` only when intentionally enforcing a reviewed score. Do not claim a passing command proves all mutants were detected: inspect tested, surviving, and uncovered counts.
- Investigate survivors rather than weakening assertions or adding blanket ignores. Parent-process coverage does not prove behavior executed in independent children.
- For runtime-guard tests, exercise the supported entry point in the parent and use `tests/Support/autoload.php` in independent PHP workers. This allows coverage-based test selection and propagates Pest's mutated source without pretending to collect child coverage.

## Test Organization

- `tests/Specification/`: consumer-facing acceptance examples and public guarantees. Show how Laravel applications use the package, with concrete inputs and visible expected results. Include real process tests when termination or timeout behavior is part of the guarantee.
- `tests/Feature/`: integration behavior, including journals, transactions, filesystem drivers, service provider wiring, commands, and Testbench-backed lifecycle tests.
- `tests/Unit/`: isolated class behavior without booting Laravel.
- `tests/ArchTest.php`: broad architecture constraints.
- `tests/Support/`: reusable fixtures and subprocess helpers, not a test suite.

Specification and Feature both use Testbench through `tests/Pest.php`; shared setup does not make their purposes interchangeable. Keep Specification separate rather than moving consumer examples into Feature merely because they boot Laravel.

Use the existing shallow responsibility-based groups:

- Specification: `Storage` for consumer file/directory examples; `Execution` for runtime and timeout guarantees.
- Feature: `Storage` for resource ownership, versions, and stages; `Filesystem` for operator configuration, prefixes, and URLs; `Drivers` for driver compatibility; `Uploads` for operation lifecycle, journals, deadlines, and execution; `Transactions` for transaction safety; `Recovery` for cleanup and reader protection; `Package` for service provider wiring; `TestSupport` for Testbench-backed harness tests.
- Unit: `Filesystem` for isolated operators; `Uploads` for isolated deadlines and execution; `TestSupport` for process helper behavior.

Choose the suite first by purpose, then the matching responsibility group. Do not put harness tests in consumer specifications or create a folder for each new test. Keep shared fixtures in `tests/Support`, not in the suite's `TestSupport` test directory.

When moving tests, check relative fixture paths, Pest setup, PHPUnit discovery, and documentation or skill references. Preserve randomized execution and independent tests; directory organization must not impose execution dependencies.

Use `SkyRaptor\LaravelVersionedStorage\Tests\Support\TestPaths::root()` to locate repository resources such as migrations and worker scripts independently of test directory depth. Keep fixture setup and assertions visible; this helper only constructs paths.

## References

- `tests/TestCase.php`
- `tests/Pest.php`
- `tests/Specification/`
- `tests/Feature/`
- `tests/Unit/`
- `tests/ArchTest.php`
- `composer.json` scripts

## Examples

- Specify file and directory versioning through public storage APIs, using distinct contents for each revision and complete expected version lists.
- Test config merge and config override by asserting default package config, then overriding the host config value in the Testbench app.
- Test publishable assets, migrations, views, lang files, or config by invoking vendor publish behavior and asserting the target path exists.
- Test routes with Testbench HTTP requests, commands with Artisan assertions, migrations with a SQLite test database, and workbench behavior after `composer build` when needed.

## Anti-Patterns

- Deleting real package tests because they are inconvenient.
- Relying only on smoke tests when behavior needs assertions.
- Testing implementation details when observable package behavior is available.
- Keeping throwaway scaffolding experiment tests in the package test suite.
