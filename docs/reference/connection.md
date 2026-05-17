---
title: Connection reference
---

# Connection reference

`Connection` stores the configuration that Quma uses to resolve SQL files, migrations, placeholders, delimiters, and PDO settings. Create it with the required DSN and SQL directory configuration, then add optional settings through fluent methods.

## Constructor

```php
new Connection(string $dsn, string|array $sql)
```

### `$dsn`

The PDO DSN. Quma extracts the driver name from the DSN prefix and verifies that the driver exists in `PDO::getAvailableDrivers()`.

If the driver is not available, `Connection` throws `RuntimeException`.

### `$sql`

Defines the SQL directories. Supported formats:

- one directory string
- a flat list of directories
- a driver map using `sqlite`, `mysql`, `pgsql`, and `all`
- mixed arrays that combine the formats above

All configured paths must already exist. Otherwise Quma throws `ValueError`.

## Example

```php
use Celemas\Quma\Connection;
use Celemas\Quma\Delimiters;
use PDO;

$conn = new Connection(
    'sqlite:' . __DIR__ . '/app.sqlite',
    __DIR__ . '/sql',
)
    ->migrations(__DIR__ . '/migrations')
    ->fetch(PDO::FETCH_ASSOC)
    ->placeholders(Delimiters::comments(), [
        'all' => ['prefix' => ''],
        'pgsql' => ['prefix' => 'cms.'],
        'mysql' => ['prefix' => 'cms_'],
    ]);
```

Configure a connection before you create or connect a `Database`. PDO settings are read when `Database` opens the PDO connection. Debug output is controlled by environment variables, so you can enable it without changing connection code.

## Basic accessors

### `dsn(): string`

Returns the configured PDO DSN.

### `driver(): string`

Returns the detected PDO driver name.

## PDO configuration

### `credentials(?string $username, ?string $password = null): static`

Sets the username and password passed to PDO.

### `username(): ?string`

Returns the configured PDO username.

### `password(): ?string`

Returns the configured PDO password.

### `options(array $options): static`

Replaces the PDO options array passed to PDO.

### `option(int $attribute, mixed $value): static`

Sets one PDO option.

### `pdoOptions(): array`

Returns the configured PDO options array.

### `fetch(int $fetchMode): static`

Sets the default fetch mode for unmapped `Query::one()`, `Query::first()`, `Query::fetch()`, `Query::all()`, and `Query::lazy()` calls when you do not pass a fetch mode explicitly.

The default is `PDO::FETCH_ASSOC`. Mapped calls that hydrate rows into objects fetch associative rows by default and reject explicit non-associative fetch modes.

### `fetchMode(): int`

Returns the configured default fetch mode.

## SQL directory methods

### `sql(): array`

Returns the resolved SQL directory list.

### `addSql(array|string $sql): static`

Prepends more SQL directories to the existing list.

This method supports the same input formats as the constructor.

## Query cache methods

### `cache(string $cacheDir): static`

Sets the cache directory for compiled `.tpql` query templates.

```php
$conn->cache(__DIR__ . '/var/cache/quma');
```

The directory must already exist, must be a directory, and must be writable. Quma does not create it automatically.

When configured, Quma writes compiled `.tpql` query templates to this directory and reuses them on later invocations. The cache does not apply to `.sql` files, migrations, or direct SQL passed to `Database::execute()`.

Keep this directory outside the public web root. The files are generated PHP templates and can be deleted safely; Quma regenerates them when needed. Cache file names include the source file metadata, active driver, and static placeholder configuration when enabled, so source or configuration changes create a new cache file.

### `noCache(): static`

Clears the configured cache directory.

### `cacheDir(): ?string`

Returns the resolved cache directory, or `null` when no cache directory is configured.

## Static placeholder methods

### `placeholders(Delimiters $delimiters, array $placeholders): static`

Enables static placeholders with explicit delimiters and replacements. If you do not call this method, Quma skips placeholder translation completely.

The recommended delimiter preset is `Delimiters::comments()`, which finds SQL comment tokens such as `/*:prefix:*/`. This keeps unprocessed SQL syntactically valid for database tools.

```php
use Celemas\Quma\Delimiters;

$conn->placeholders(Delimiters::comments(), [
    'all' => ['prefix' => ''],
    'pgsql' => ['prefix' => 'cms.'],
    'mysql' => ['prefix' => 'cms_'],
]);
```

The top-level `all` scope applies to every driver. A driver-specific scope such as `sqlite`, `mysql`, or `pgsql` overrides `all` for that driver. Static placeholder names must match `[A-Za-z_][A-Za-z0-9_.:-]*`. Values must be strings and are inserted as raw SQL text. Quma does not quote or escape them.

Use `Delimiters::brackets()` for the legacy `[::name::]` style or `new Delimiters('[[', ']]')` for custom syntax. Delimiter strings must not be empty and must not contain NUL bytes. Choose delimiters that do not collide with SQL syntax, PDO parameters, or template code.

### `placeholderDelimiters(): ?Delimiters`

Returns the configured static placeholder delimiters, or `null` when placeholders are disabled.

### `placeholderValues(): array`

Returns the static placeholder map resolved for the active driver. Returns an empty array when placeholders are disabled.

### `applyPlaceholders(string $source, string $path, bool $isTemplate = false): string`

Applies static placeholders to SQL or template source. When placeholders are disabled, this returns the source unchanged. This method is used internally by file-based queries and migrations.

### `assertNoTemplatePlaceholders(string $source, string $path): void`

Throws when rendered template output still contains a configured static placeholder token. This catches unsupported static placeholders generated from PHP code blocks. When placeholders are disabled, this method is a no-op.

## Migration directory methods

### `migrations(string|array $migrations): static`

Sets the migration directories. Supported formats:

- one directory string
- a flat list of directories
- a namespaced map such as `['default' => '/path/to/migrations']`

If the array is associative and not a driver map, Quma treats it as namespaced migration configuration.

### `migrationDirs(): array`

Returns either:

- a flat list of directories
- a namespaced map of directories

### `addMigration(string $migrations): static`

Adds a migration directory to a flat migration configuration.

If the connection uses namespaced migrations, this method throws `ValueError`. Use `migrationNamespace()` for namespaced migrations.

### `migrationNamespace(string $namespace, string|array $dirs): static`

Adds or replaces one namespaced migration directory entry.

```php
$conn
    ->migrationNamespace('default', __DIR__ . '/migrations/core')
    ->migrationNamespace('install', __DIR__ . '/migrations/install');
```

If the connection already has flat migration directories, this method throws `ValueError`.

## Migration metadata naming

### `migrationTable(string $table): static`

Sets the migrations table name.

Validation rules:

- for SQLite and MySQL, only letters, numbers, and underscores are allowed
- for PostgreSQL, one optional schema prefix is allowed, for example `public.migrations`

### `migrationColumns(string $migration, string $applied = 'applied'): static`

Sets the migration name column and applied-at column.

### `migrationsTable(): string`

Returns the validated migrations table name.

### `migrationsColumnMigration(): string`

Returns the validated migration name column.

### `migrationsColumnApplied(): string`

Returns the validated applied-at column.

Quma uses these names when it creates the metadata table, checks applied migrations, and records newly applied migrations. For PostgreSQL, a schema-qualified table name such as `public.migrations` is supported.

## Debug output

> **⚠ Warning — Development only.** Never enable debug output in production. `QUMA_DEBUG_INTERPOLATED` writes real query data (secrets, credentials, tokens, PII) to disk, and `QUMA_DEBUG_PRINT` prints it to stdout or error log. There is no built-in production guard — the debug system activates solely from environment variables.

Quma debug output is controlled through environment variables instead of connection methods. Set `QUMA_DEBUG` to a true flag value before creating the `Database` instance, then choose one or more output channels.

```bash
QUMA_DEBUG=1 QUMA_DEBUG_PRINT=1 php app.php
QUMA_DEBUG=1 QUMA_DEBUG_TRANSLATED=/tmp/quma/translated php app.php
QUMA_DEBUG=1 QUMA_DEBUG_INTERPOLATED=/tmp/quma/interpolated php app.php
QUMA_DEBUG=1 QUMA_DEBUG_SESSION=manual-session-id QUMA_DEBUG_PRINT=1 php app.php
```

- `QUMA_DEBUG` enables debug handling for new `Database` instances when set to `1`, `true`, `yes`, or `on` case-insensitively. Any other value disables it.
- `QUMA_DEBUG_PRINT` prints interpolated SQL when set to a true flag value.
- `QUMA_DEBUG_TRANSLATED` writes runtime SQL before parameter interpolation. For `.tpql` files, this is after template rendering with the current input.
- `QUMA_DEBUG_INTERPOLATED` writes runtime SQL after template rendering and parameter interpolation.
- `QUMA_DEBUG_SESSION` overrides automatic session naming.

Debug directories must already exist and be writable. Translated and interpolated files are written below `<dir>/<session>/0001--...`. Add driver or output-type directories to the environment variable value if you want them. In HTTP contexts, the session directory includes request time, method, a sanitized URI path, and a short hash. In CLI contexts, it includes process start time and a short hash. The four-digit counter preserves query order inside the session.

Interpolated SQL can contain secrets or user data. Use these options only for local debugging, keep the directories outside the public web root, and do not commit their contents. Parameter interpolation is a best-effort debug representation; PDO still executes prepared statements with bound parameters.
