# Contribution Guide

Thank you for contributing to Laravel Versioned Storage. For significant
changes, please open an issue first to discuss the behavior and approach.

## Contributor Agreement

Before submitting contributions, accept the [Contributor Licence Agreement
version 1.0](../CLA.md) through a separate
[signing pull request](cla/README.md). It permits future AGPL versions and
changes to additional permissions or exceptions, not separate proprietary
licensing. Contributors retain copyright. Existing contributions are not
retroactively covered.

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
suites. The development command reference matches
[AGENTS.md](../AGENTS.md#quick-commands); commands are defined in
[composer.json](../composer.json).

| Purpose | Command |
| --- | --- |
| Full validation | `composer test` |
| All Pest suites, including architecture | `composer test:all` |
| Unit suite | `composer test:unit` |
| Feature suite | `composer test:feature` |
| Integration suite | `composer test:integration` |
| Type coverage (100%) | `composer test:types` |
| Static analysis | `composer analyse` |
| Check formatting | `composer lint:check` |
| Apply formatting | `composer lint` |
| Mutation testing (opt-in; coverage driver required) | `composer test:mutation` |
| Build Workbench | `composer build` |
| Serve Workbench | `composer serve` |

`composer test:all` runs every Pest suite, including architecture checks.
`test:unit`, `test:feature`, and `test:integration` select only their named
suite; `composer test` still runs every validation check and every suite.
`composer test:types` disables Pest's shared type-coverage cache to
avoid corrupted cache files during concurrent analysis; it still enforces
100% type coverage. For a focused test run:

```bash
vendor/bin/pest tests/Integration/PublicApi/FileTest.php
```

CI validates Ubuntu and macOS across the supported PHP/Laravel versions
with lowest and stable dependency combinations.

## Source Layout

Keep consumer entry points (`VersionedStorage`, `VersionedStorageHandler`,
`StagedVersion`), shared `ResourceIdentity`, and the service provider at the source
root. Group supporting classes by responsibility and genuine implementation family:

| Directory | Responsibility and reason |
| --- | --- |
| `src/Actions` | Business decisions and ordered steps for writing, reading, publication, registration, recovery, and cleanup |
| `src/Database` | Metadata queries, locks, transaction recovery, and operation journals that coordinate processes and survive crashes |
| `src/Filesystem` | Snapshot view construction, filesystem ownership, and verified physical deletion |
| `src/Filesystem/Adapters` | Laravel and Flysystem wrappers that scope snapshot paths and route callback operations |
| `src/Filesystem/Execution` | Filesystem-operation coordination, deadlines, execution, and process supervision; never the entire upload callback or database work |
| `src/Filesystem/Execution/Contracts` | The execution-policy replacement boundary owned by filesystem execution |
| `src/Filesystem/Execution/Policies` | Runtime-context and synchronous-testing implementations of the execution-policy contract |
| `src/Console/Commands` | Laravel command input, reporting, and exit status; delegate business work to actions |
| `src/Facades` | Laravel facade exposing the consumer API |
| `src/Testing` | Application testing helpers that switch execution policy while preserving real metadata/filesystem behavior |

Read [Implementation architecture](../docs/architecture.md) for the three resource
workflows, UML diagrams, transaction boundaries, and object lifetimes.
Use named methods for ordinary workflow steps; extract a class only for a
cohesive mechanism with its own contract. Test those mechanisms directly and
validate workflows through public APIs and the existing process tests. Keep
guards and atomic updates together; method boundaries do not imply commits.
Keep business decisions in actions, not filesystem executors or journals.
Lifecycle describes a class's steps, lifetime, and failure handling, not a
directory hierarchy. Tests retain their purpose-based groups independently of
the production namespaces.

Keep contracts with the subsystem they govern and group interchangeable
implementations together. Adapters reuse Laravel/Flysystem contracts; do not add
an interface for every class or a directory for each supporting mechanism.

## Writing Tests

Choose the category that matches the behavior:

- `tests/Unit`: isolated behavior without booting Laravel or resolving services
  from its container. Framework value objects and contracts can be used without
  starting an application.
- `tests/Feature`: focused Laravel wiring and component interactions through
  Orchestra Testbench: configuration, adapters, commands, metadata, journals,
  and lifecycle mechanisms.
- `tests/Integration`: complete public workflows and actual infrastructure:
  snapshots, version history, rollback, recovery, concurrent processes, and
  enforced timeouts. `PublicApi` contains readable consumer specifications;
  specification is a purpose, not another testing level.
- `tests/ArchTest.php`: architecture constraints.
- `tests/Support`: reusable fixtures and subprocess helpers, not a test suite.

Within each suite, tests are grouped by responsibility:

| Suite | Subdirectories |
| --- | --- |
| Integration | `PublicApi`, `Storage`, `Transactions`, `Concurrency`, `Recovery`, `Execution`, `TestSupport` |
| Feature | `Database`, `Filesystem`, `Drivers`, `Uploads`, `Recovery`, `Execution`, `Package` |
| Unit | `Actions`, `Database`, `Filesystem`, `Uploads`, `TestSupport` |

Use the existing group matching the behavior. For example, run all driver
integration tests with `vendor/bin/pest tests/Feature/Drivers`. Grouping is
for navigation; tests must remain independent of execution order.

Prefer observable behavior over internal query shapes or incidental call
counts, except where a query is the deliberate crash or durability boundary.
Use descriptive English names, arrange/act/assert spacing, distinct revision
contents, and complete expected results. Split tests when a boolean changes
the workflow or its assertions. Small named datasets are useful for inputs
to the same behavior; do not encode different workflows in a scenario table.
Prefer named values and time advancement over unexplained offsets or indexes.
Fixtures may own repetitive infrastructure setup; keep actions and expected
outcomes visible in the test. Do not build a generic scenario framework.

Ordinary application-backed tests use the synchronous upload helper with
real metadata and fake disks. See the [README testing section](../README.md#testing-in-laravel-applications).
Laravel's local fake disk still writes real files in an isolated directory;
it is not proof of a remote service. Driver Feature tests use real adapters
with simulated transports, not live S3, FTP, or SFTP servers.
Testbench supplies the Laravel host for both application-backed suites.
Workbench is used where actual Artisan/queue/HTTP host behavior is required,
not as a replacement for every Testbench database test. End-to-end tests
of a consuming application's own behavior belong in that application.
Keep real process tests where OS termination, concurrency, or crash recovery
is the requirement; synchronous execution cannot prove those guarantees.
Build a broad Unit foundation for individual methods, state transitions,
failure paths, and independent decision conditions. Feature tests should be
fewer and prove composition with Laravel and real metadata. Integration tests
should be fewer again: representative consumer histories and indispensable
infrastructure guarantees, not every permutation of an already isolated rule.
When moving a detailed case down, retain a focused wiring proof and map the
original guarantee to its replacement. Do not duplicate trivial cases to
inflate counts or replace real database locks, atomicity, process termination,
or crash recovery with mocks. Independent-condition examples are useful, but
do not describe them as formal MC/DC without measuring that criterion.

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

### Mutation Testing

Pest includes mutation testing. Enable PCOV or Xdebug 3 coverage mode, then
run:

```bash
composer test:mutation
```

The command generates mutations across the package source configured in
`phpunit.xml.dist`, including code without explicit `covers()` or `mutates()`
declarations. It is intentionally separate from `composer test`: mutation
testing is substantially slower and requires a coverage driver.

Runtime-guard workers use `tests/Support/autoload.php` to inherit Pest's
source replacement during mutation runs. Their tests also exercise the
supported entry point in the parent so Pest can select them from coverage.
This does not collect coverage from independent child processes.

For an optional targeted investigation, invoke Pest directly:

```bash
vendor/bin/pest --mutate --parallel --class='SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadOperation' --no-cache
```

The Composer command always uses `--everything`. Use direct Pest invocation
for class selection because `--everything` overrides `--class`.

With Xdebug, prefix the command with `XDEBUG_MODE=coverage`. Pest reports
detected, surviving, and uncovered mutations. Review survivors for missing
assertions or equivalent behavior; do not suppress them merely to improve
the score. Use `--no-cache` for a fresh measurement. No minimum score is
enforced by default; use `--min=80` to enforce a chosen threshold.

Mutation testing does not replace real process tests or randomized stability
runs. Coverage collected in the parent may not capture work in independent
subprocesses.

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
