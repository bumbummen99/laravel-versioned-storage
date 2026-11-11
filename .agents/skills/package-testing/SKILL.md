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
3. Choose the lowest test level that can prove the behavior using the organization below.
4. Invoke Pest directly for focused files, or use `composer test:unit`, `composer test:feature`, and `composer test:integration` for their named suites. Use `composer test:all` for every Pest suite, `composer test:types` when type-sensitive code changed, and `composer test` before finishing.
5. Keep real package tests in the suite and remove only throwaway tests that were explicitly created for local scaffolding experiments.

## Mutation Testing

- `composer test:mutation` runs Pest's bundled mutation plugin in parallel over the whole package using `--everything` and the PHPUnit source scope, including classes without explicit mutation declarations. It is opt-in and not part of `composer test`.
- Requires PCOV or Xdebug 3 with coverage enabled; use `XDEBUG_MODE=coverage` when using Xdebug.
- Whole-package analysis is the default. For optional targeted investigations, invoke `vendor/bin/pest --mutate --parallel --class=...` directly. Do not add `--class` to the Composer command: Pest ignores class selection with `--everything`.
- Use `--no-cache` to measure fresh results and `--min=...` only when intentionally enforcing a reviewed score. Do not claim a passing command proves all mutants were detected: inspect tested, surviving, and uncovered counts.
- Investigate survivors rather than weakening assertions or adding blanket ignores. Parent-process coverage does not prove behavior executed in independent children.
- For runtime-guard tests, exercise the supported entry point in the parent and use `tests/Support/autoload.php` in independent PHP workers. This allows coverage-based test selection and propagates Pest's mutated source without pretending to collect child coverage.

## Test Organization

- `tests/Unit/`: isolated behavior without booting Laravel or resolving its container. Using a framework contract or value object does not itself require Testbench.
- `tests/Feature/`: focused Laravel wiring/component interactions: configuration, adapters, commands, metadata, journals, and lifecycle mechanisms.
- `tests/Integration/`: complete workflows and actual infrastructure: public storage APIs, version history, transactions, recovery, concurrent processes, Workbench workers, and real FPM timeout guarantees.
- `tests/ArchTest.php`: broad architecture constraints.
- `tests/Support/`: reusable fixtures and subprocess helpers, not a test suite.

Feature and Integration use Testbench through `tests/Pest.php`; Unit does not.
Consumer specifications live in `Integration/PublicApi`: specification describes
their intent, not another test level. Workbench supplies actual Artisan/queue/HTTP
host behavior where needed; it is not required for every database test.

Use the existing shallow responsibility-based groups:

- Integration: `PublicApi` for consumer examples; `Storage` for resource isolation/reservations and primary metadata; `Transactions` for publication/rollback guarantees; `Concurrency` for competing registrations/publications and protected readers; `Recovery` for reclamation/deletion workflows; `Execution` for real processes/runtime enforcement; `TestSupport` for real infrastructure fixture tests.
- Feature: `Database` for metadata/transaction mechanisms; `Filesystem` for operator configuration, prefixes, and URLs; `Drivers` for real adapters with simulated transports; `Uploads` for focused upload/journal orchestration; `Recovery` for reclamation and batch reconciliation mechanisms; `Execution` for container/runtime-policy and transfer wiring; `Package` for service provider wiring.
- Unit: `Actions` for isolated workflow decisions; `Database` for query policy and transaction ownership; `Filesystem` for isolated operators and ownership; `Uploads` for isolated deadlines and execution; `TestSupport` for process helper behavior.

Choose the suite first by level, then the matching responsibility group. Do not put harness tests in public API examples or create a folder for each new test. Keep shared fixtures in `tests/Support`, not in the suite's `TestSupport` test directory. Driver transport fixtures belong in `tests/Support/Drivers`; they do not prove live remote service compatibility.

Use descriptive English names and visible arrange/act/assert steps. Prefer explicit
file and directory examples to boolean-driven workflow matrices. Keep small named
datasets for inputs to the same behavior, not branching scenario programs. Helpers
may simplify setup but must not hide actions or expected outcomes. Build a
bottom-up pyramid: substantially more framework-free method, transition, and
decision tests than Feature composition tests, and more Feature tests than
representative Integration workflows. Move detailed permutations down rather
than merely renaming directories. Map each replaced scenario to its isolated
rule and retained wiring or real infrastructure proof. Do not pad counts with
trivial duplicates or mock away database locks, atomicity, crash recovery, or
process termination. Independent condition cases do not establish formal
MC/DC without measurement.

When refactoring tests, inventory scenarios and capture full-suite coverage and a
fresh mutation baseline first. Preserve every scenario, timing observation, and
durability boundary. Compare covered source lines/methods and mutation outcomes
afterward; passing tests alone do not prove parity. Never shrink source scope,
exclude mutants, or skip infrastructure cases to improve the result.

When moving tests, check relative fixture paths, Pest setup, PHPUnit discovery, and documentation or skill references. Preserve randomized execution and independent tests; directory organization must not impose execution dependencies.

Use `SkyRaptor\LaravelVersionedStorage\Tests\Support\TestPaths::root()` to locate repository resources such as migrations and worker scripts independently of test directory depth. Keep fixture setup and assertions visible; this helper only constructs paths.

## References

- `tests/TestCase.php`
- `tests/Pest.php`
- `tests/Integration/`
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
