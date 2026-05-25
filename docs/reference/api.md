---
title: API reference
---

# API reference

This page summarizes the main public types that application code works with directly.

## `Celemas\Quma\Connection`

Stores DSN, SQL directory, migration, placeholder, delimiter, and PDO configuration.

### Constructor

```php
new Connection(string $dsn, string|array $sql)
```

### Key methods

- `credentials(string $username, ?string $password = null): static` sets PDO credentials
- `options(array $options): static` replaces PDO options
- `option(int $attribute, mixed $value): static` sets one PDO option
- `fetch(int $fetchMode): static` sets the default query fetch mode
- `migrations(string|array $migrations): static` sets migration directories
- `addMigration(string $migrations): static` prepends one flat migration directory
- `migrationNamespace(string $namespace, string|array $dirs): static` sets one namespaced migration entry
- `migrationTable(string $table): static` sets the migration metadata table
- `migrationColumns(string $migration, string $applied = 'applied'): static` sets migration metadata columns
- `placeholders(Delimiters $delimiters, array $placeholders): static` sets static placeholder delimiters and replacements
- `addSql(array|string $sql): static` prepends SQL directories

Read resolved configuration through `$conn->config`. See [Connection reference](connection.md) for config properties and configuration formats.

## `Celemas\Quma\Config`

Read-only resolved connection configuration.

Common properties:

- `dsn: string`
- `driver: string`
- `sql: array`
- `migrations: array`
- `migrationsTable: string`
- `migrationsColumnMigration: string`
- `migrationsColumnApplied: string`
- `pdo: PdoConfig`
- `placeholders: ?Placeholders`

## `Celemas\Quma\PdoConfig`

Read-only resolved PDO configuration.

Properties:

- `username: ?string`
- `password: ?string`
- `options: array`
- `fetchMode: int`

Methods:

- `effectiveOptions(): array` returns PDO options with Quma defaults and required exception mode applied

## `Celemas\Quma\Delimiters`

Configures static placeholder delimiters.

```php
Delimiters::comments() // /*:name:*/
Delimiters::brackets() // [::name::]
new Delimiters(string $open, string $close)
```

Delimiter strings must not be empty and must not contain NUL bytes. Use `Delimiters::comments()` for SQL-friendly comment placeholders.

## `Celemas\Quma\Database`

The main entry point for query execution.

### Constructor

```php
new Database(Connection $conn)
```

### Key methods

- `connect(): static` opens the PDO connection lazily
- `connected(): bool` reports whether `Database` currently holds a live PDO instance
- `disconnect(): void` drops the current PDO connection and clears tracked connection state
- `reconnect(): static` closes the current PDO connection and opens a fresh one
- `ping(): bool` runs a lightweight `SELECT 1` health check against the active PDO connection; it returns `false` when disconnected or when the check fails
- `reset(): void` rolls back any open transaction and keeps the connection available for reuse
- `getConn(): PDO` returns the PDO instance
- `quote(string $value): string` proxies to `PDO::quote()`
- `begin(): bool` starts a transaction
- `commit(): bool` commits the current transaction
- `rollback(): bool` rolls back the current transaction
- `execute(string $query, mixed ...$args): Query` runs ad-hoc SQL through the same query pipeline as file-based queries
- `getFetchMode(): int` returns the configured default fetch mode
- `getPdoDriver(): string` returns the active PDO driver name
- `getSqlDirs(): array` returns the resolved SQL directory list

These lifecycle methods are especially useful when you keep one `Database` instance alive in a long-running PHP process and need explicit control over the underlying PDO handle.

### Properties

- `readonly bool $debug` reports whether debug handling was enabled when this `Database` instance was created

### Dynamic access

`Database` uses magic property access to resolve folders.

```php
$db->users;
```

This returns a `Folder` instance for the `users` SQL directory.

## `Celemas\Quma\Folder`

Represents one SQL folder.

### Dynamic access

- `$db->users->byId` returns a `Script`
- `$db->users->byId(42)` returns a `Query`

If the script file does not exist, `Folder` throws `RuntimeException`.

## `Celemas\Quma\Script`

Wraps a static `.sql` file or a template `.tpql` file.

### Key methods

- `__invoke(mixed ...$args): Query`
- `invoke(mixed ...$args): Query`

Most application code uses a `Script` implicitly through `$db->folder->queryName(...)`.

## `Celemas\Quma\Query`

Represents a prepared query.

### Execution methods

- `one(string|Closure|null $map = null, ?int $fetchMode = null): array|object` returns the only row and throws `UnexpectedResultCountException` unless exactly one row exists
- `first(string|Closure|null $map = null, ?int $fetchMode = null): array|object|null` returns the first row or `null`
- `fetch(string|Closure|null $map = null, ?int $fetchMode = null): array|object|null` returns the next row from a cursor or `null`
- `all(string|Closure|null $map = null, ?int $fetchMode = null): array` returns every row
- `lazy(string|Closure|null $map = null, ?int $fetchMode = null): Generator` streams rows
- `run(): bool` executes a statement for its success value
- `len(): int` returns `PDOStatement::rowCount()`

Pass a class name or resolver closure as `$map` to hydrate rows into objects. Leave `$map` as `null` for raw arrays. The per-call fetch mode is the second argument or the `fetchMode` named argument.

### Debug helpers

- `interpolate(): string` returns a best-effort interpolated SQL string for debugging
- `__toString(): string` proxies to `interpolate()`

### Query result exceptions

- `UnexpectedResultCountException` is thrown by `Query::one()` when the result has zero rows or more than one row.

## Debug environment variables

> **⚠ Warning — Development only.** Never set these environment variables in production. `QUMA_DEBUG_INTERPOLATED` writes real query data (secrets, credentials, tokens, PII) to disk, and `QUMA_DEBUG_PRINT` prints it to stdout or error log. There is no built-in production guard — the debug system activates solely from environment variables.

Quma debug output is controlled through environment variables, not connection code. Set `QUMA_DEBUG` to a true flag value before creating the `Database` instance, then choose one or more output channels.

- `QUMA_DEBUG` enables debug handling for new `Database` instances when set to `1`, `true`, `yes`, or `on` case-insensitively. Any other value disables it.
- `QUMA_DEBUG_PRINT` prints interpolated SQL when set to a true flag value.
- `QUMA_DEBUG_TRANSLATED=/path/to/dir` writes runtime SQL before parameter interpolation. For `.tpql` files, this is after template rendering with the current input.
- `QUMA_DEBUG_INTERPOLATED=/path/to/dir` writes runtime SQL after template rendering and parameter interpolation.
- `QUMA_DEBUG_SESSION=name` overrides automatic session naming.

Debug directories must already exist and be writable. Keep them outside the public web root and do not commit their contents.

## Row hydration types

### `Celemas\Quma\Column`

Constructor-parameter attribute for mapping a parameter to a different row column.

```php
#[Column('email_address')]
public string $email
```

### `Celemas\Quma\Hydratable`

Interface for classes that own custom row hydration.

```php
/** @param array<string, mixed> $row */
public static function fromRow(array $row): static;
```

### Hydration exceptions

- `HydrationException` is the base exception for built-in hydration failures.
- `MissingColumnException` is thrown when a required constructor parameter has no matching row column.
- `TypeCoercionException` is thrown when a present value cannot be converted to the declared parameter type.
- `InvalidHydrationTargetException` is thrown for invalid targets, unsupported constructor shapes, unsupported parameter types, invalid `#[Column]` values, or invalid resolver results.

## `Celemas\Quma\Environment`

Provides the runtime context for migration commands and PHP migrations.

### Public properties

- `$conn`
- `$driver`
- `$showStacktrace`
- `$table`
- `$columnMigration`
- `$columnApplied`
- `$db`
- `$options`

### Key methods

- `getMigrations(): array|false`
- `checkIfMigrationsTableExists(Database $db): bool`
- `getMigrationsTableDDL(): string|false`

## `Celemas\Quma\Contract\Migration`

Implemented by PHP migrations.

```php
interface Migration
{
    public function run(Environment $env): void;
}
```

PHP migration files must return a class name that implements this contract.

## `Celemas\Quma\Contract\MigrationFactory`

Optional factory for constructing PHP migrations with custom constructors.

```php
interface MigrationFactory
{
    /** @param class-string<Migration> $class */
    public function create(string $class, Environment $env): Migration;
}
```

## `Celemas\Quma\Commands`

Factory for the bundled CLI commands.

### Static factory

```php
Commands::get(
    array|Connection $conn,
    array $options = [],
    ?MigrationFactory $migrationFactory = null,
): Celemas\Cli\Commands
```

Pass either one `Connection` or an array of named connections. Pass a migration factory when PHP migrations need constructor dependency injection.

## Internal helper types you will see in the codebase

These types are part of the public namespace, but most application code does not need to instantiate them directly:

- `Args`
- `ArgType`
- `PreparedQuery`
- `Placeholders`
- `Util`
