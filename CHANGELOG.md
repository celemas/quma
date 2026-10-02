# Changelog

## [Unreleased](https://codefloe.com/celema/quma/compare/0.5.0...HEAD)

### Breaking

- `QUMA_DEBUG_PRINT` writes to PHP's error log (stderr in the CLI unless `error_log` is configured) instead of stdout. Without `SERVER_SOFTWARE`, for example in a FrankenPHP worker at boot or a RoadRunner worker, the output went to stdout and corrupted responses.
- `Database::reset()` returns whether an open connection is kept. It no longer throws when the rollback fails; the broken connection is dropped and the next statement connects anew. If a query or a transaction call failed since the last reset, `reset()` also pings the connection and drops it if it is broken, which replaces a lost MySQL connection even while it is used continuously.

### Added

- Before a connection that was idle for at least `pingAfterIdle` seconds (default 60) is reused, `Database` pings it and connects anew if it is broken. `Connection::maxConnectionAge()` replaces connections after a number of seconds (default 0, never). Neither check runs inside a transaction, and failed statements are never retried. A query built before its connection was replaced is prepared again on the new one.
- Documentation for long-running processes: resetting between units of work, connection budgets, transaction-local session state, and cached scripts.

### Fixed

- Template queries accept no arguments or an empty array and render without parameters. Before, both threw `InvalidArgumentException` like positional arguments, so a template whose parameters are all optional could not be called without a dummy named parameter.

## [0.5.0](https://codefloe.com/celema/quma/src/tag/0.5.0) (2026-09-27)

### Breaking

- Constructorless hydration targets now throw `InvalidHydrationTarget` unless they implement `Hydratable`. This includes empty classes and property-only DTOs. Add a public constructor or implement `Hydratable::fromRow()` for affected targets. Public inherited and explicit zero-argument constructors remain supported.
- Unmapped query methods now consistently accept only `PDO::FETCH_ASSOC`, `PDO::FETCH_NUM`, `PDO::FETCH_BOTH`, and `PDO::FETCH_NAMED`. Other fetch modes and mode flags throw `InvalidArgumentException` before execution instead of returning inconsistent results or silently reporting missing rows. Code using other PDO fetch modes through `all()` must prepare and execute statements directly through `Database::getConn()` instead.

## [0.4.0](https://codefloe.com/celema/quma/src/tag/0.4.0) (2026-07-20)

### Breaking

- Adopted the attribute-based command API of `celema/console` 0.5. The commands are now plain `#[Command]` classes invoked via `__invoke(Args $args, Io $io)`; the shared `Celema\Quma\Commands\Command` base class was removed. `Commands::get()` is unchanged but registers lazy factories, so an unknown `--conn` now surfaces as a runner error message instead of an uncaught `RuntimeException` during registration.
- The commands are strict per console 0.5: undeclared options and positionals are rejected before a command runs. `db:add-migration` declares `--conn`, and `db:create-migrations-table` declares `--conn` and `--stacktrace`, so those keep working and now show in the command help.
- `db:add-migration` takes the migration file name as an optional positional argument — `php run db:add-migration create-users.sql` — instead of the removed `--file`/`-f` option; without a name it still prompts interactively. The argument is declared via `#[Arg]`, so surplus positionals are rejected before the command runs, and a dashed file name can be passed after the `--` separator.

### Changed

- `Environment` accepts a single `Connection` besides a connection array and defaults `options` to `[]`.
- `db:add-migration` prompts for the file name and prints its messages through the console `Io` instead of `readline()` and raw `echo`, so the prompt path is testable with `BufferedIo`.
- The whole migrations machinery prints through the console `Io` instead of native `echo` with hardcoded escape codes: colors are now inline markup (honoring `NO_COLOR`, terminal detection, and `BufferedIo`), errors and warnings go to STDERR, and exception messages are escaped. `Plan`, `Runner`, `Executor`, and `MetadataTable` take the `Io` as an additional constructor argument, `CreateMigrationsTable::__invoke()` takes only `Io`, and `Environment::getMigrations()` no longer prints — the migrations command reports the missing-directories problem itself.
- The `--test-run` confirmation renders through the console `Io` as well and uses its `confirm()` prompt in interactive shells; non-interactive runs still require `--yes`.

## [0.3.0](https://codefloe.com/celema/quma/src/tag/0.3.0) (2026-07-18)

### Breaking

- Renamed the package from `celemas/quma` to `celema/quma` and the root namespace from `Celemas\Quma` to `Celema\Quma`.
- Moved the source repository to the Celema organization and updated the project domain and contact email.
- Moved Quma exceptions to `Celema\Quma\Exception`, renamed hydration failures to `HydrationFailure`, renamed type-coercion failures to `InvalidTypeCoercion`, and removed the `Exception` suffix from class names.
- Changed `db:migrations` without `--apply` to plan-only mode for every driver. Use `--test-run --yes` for the previous transactional execute-and-rollback behavior on SQLite and PostgreSQL.
- Removed the runtime `ext-tokenizer` requirement.
- Removed `Connection::applyPlaceholders()`, `Connection::assertNoTemplatePlaceholders()`, `Connection::cache()`, `Connection::noCache()`, `Config::$cacheDir`, placeholder introspection helpers, and the old direct `Script` constructor shape.
- Changed `.tpql` query and migration execution to render templates before applying static placeholders.
- Stopped writing persistent compiled `.tpql` query cache files.

### Added

- Added `db:migrations --test-run` for explicit transactional execute-and-rollback migration test runs on SQLite and PostgreSQL.
- Added support for trusted static placeholders generated by rendered `.tpql` output.

## [0.2.0](https://codefloe.com/celema/quma/src/tag/0.2.0) (2026-05-17)

### Breaking

- Renamed the Composer package, root namespace, repository URLs, homepage, and contact email from Duon to Celemas.
- Changed `Connection` setup to take only DSN and SQL directories in the constructor; credentials, PDO options, fetch mode, migrations, placeholders, cache, and migration metadata now use fluent methods.
- Replaced direct `Connection` properties and getter methods with read-only `Connection::$config` for inspecting resolved configuration.
- Required `ext-tokenizer` at runtime for template placeholder parsing.
- Changed the default query fetch mode from `PDO::FETCH_BOTH` to `PDO::FETCH_ASSOC`.
- Changed query terminal method signatures so the optional hydration map is the first argument and the per-call fetch mode is the second argument or `fetchMode` named argument.
- Changed `Query::one()` to require exactly one row and throw on empty or multi-row results.
- Changed non-default migration namespaces to record applied migrations as `namespace:basename`.
- Removed `Connection::print()` and `Database::print()` in favor of `QUMA_DEBUG` and `QUMA_DEBUG_PRINT`.
- Changed PHP migrations to return class names implementing `Contract\Migration` and support optional `Contract\MigrationFactory` construction.

### Added

- Added opt-in, driver-aware static placeholders for trusted SQL fragments through `Connection::placeholders(Delimiters, ...)`, including `/*:name:*/`, `[::name::]`, and custom delimiter support.
- Added static placeholder support to `.sql` queries, `.tpql` query templates, `.sql` migrations, and `.tpql` migrations.
- Added optional `.tpql` query template caching via `Connection::cache()`.
- Added environment-controlled SQL debugging with `QUMA_DEBUG`, `QUMA_DEBUG_PRINT`, `QUMA_DEBUG_TRANSLATED`, `QUMA_DEBUG_INTERPOLATED`, and `QUMA_DEBUG_SESSION`, including session-grouped translated and interpolated SQL files. #10
- Added `Database::$debug` to expose whether debug handling was enabled when a database handle was created.
- Added `Database` lifecycle helpers: `connected()`, `disconnect()`, `reconnect()`, `ping()`, and `reset()`.
- Added `Query::first()` for stable first-row reads and `Query::fetch()` for cursor-style reads.
- Added optional row hydration for `one()`, `first()`, `fetch()`, `all()`, and `lazy()` through class-string targets, resolver closures, `#[Column]`, `Hydratable`, and hydration-specific exceptions. #8

### Changed

- Changed MySQL migration dry runs to print a pending migration plan without creating tables or applying migrations unless `--apply` is used.

### Fixed

- Fixed PDO option precedence so custom options override Quma defaults except for required exception error mode.
- Fixed migration metadata handling to use configured table and column names consistently, including current-database table checks on MySQL.
- Fixed duplicate migration IDs to abort before running the migration batch.
- Fixed array query parameters with invalid JSON input to fail instead of binding an empty string.
- Fixed generated PHP migration stubs to use the current migration contract signature.
- Fixed dynamic SQL folder and script resolution to reject invalid path segments.

## [0.1.1](https://codefloe.com/celema/quma/src/tag/0.1.1) (2026-02-07)

### Changed

- Reworked template execution for `*.tpql` and PHP migrations to load files via `include`/`require` instead of evaluating raw file contents.
- Improved SQL and migration directory parsing for nested and namespaced configurations, including safer handling of invalid entries.
- Hardened named-parameter preparation for template queries to keep only placeholders that are actually present in rendered SQL.

### Fixed

- Added stricter migration loading validation with clearer failures for missing files and invalid migration objects.
- Added a defensive runtime guard when reading the PDO connection before initialization.

## [0.1.0](https://codefloe.com/celema/quma/src/tag/0.1.0) (2026-01-31)

Initial release.

### Added

- No-ORM database library for executing raw SQL files
- SQL file organization and query management
- Database migration support
- PDO-based connection handling
