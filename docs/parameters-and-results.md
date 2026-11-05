---
title: Parameters and results
---

# Parameters and results

Quma binds parameters through PDO and returns a `Query` object that you can execute in several ways.

## Positional parameters

Use positional arguments with `?` placeholders.

```sql
SELECT id, email FROM users WHERE id = ?;
```

```php
$user = $db->users->byId(42)->one();
```

You can also pass the positional arguments as a list inside one array.

```php
$user = $db->users->byId([42])->one();
```

## Named parameters

Use PHP named arguments for named placeholders.

```sql
SELECT id, email FROM users WHERE email = :email;
```

```php
$user = $db->users->byEmail(email: 'someone@example.com')->one();
```

You can also pass them as one associative array.

```php
$user = $db->users->byEmail([
    'email' => 'someone@example.com',
])->one();
```

Mixing positional and named arguments throws `InvalidArgumentException`, as does an array that is neither a list nor keyed by names.

## Missing parameters

A query that lacks a value for one of its placeholders throws `Celema\Quma\Exception\MissingParameter` when it is created. Text in strings, comments, and blocks does not count as a placeholder.

Two forms stay valid when no values are bound, because they are PostgreSQL syntax rather than placeholders: array slices such as `a[1:n]` and the `?` operators.

## Supported parameter types

Quma supports these parameter value types:

- `bool`
- `int`
- `string`
- `null`
- `array`

Arrays are JSON-encoded before binding, except for a [list after `IN`](#lists-after-in).

## Lists after `IN`

A named array parameter directly after `IN` or `NOT IN`, without parentheses, becomes one placeholder per element on every driver:

```sql
SELECT id, email FROM users WHERE id IN :ids;
```

```php
$users = $db->users->byIds(ids: [1, 2, 3])->all();
```

- An empty array throws `InvalidArgumentException`, because `IN ()` is not valid SQL. Check for it before the call and skip the query.
- Elsewhere an array is still JSON. That includes `IN (:ids)`, which compares against a single JSON string, and PostgreSQL's `IN (SELECT jsonb_array_elements_text(:ids::jsonb))` and `= ANY(...)`.
- Positional parameters are not expanded.
- The placeholders are named like `:ids__0`, so a parameter with such a name throws `InvalidArgumentException`.

If you pass any other type, Quma throws `InvalidArgumentException`.

## Fetch exactly one row

Use `one()` when the query must return exactly one row.

```php
$user = $db->users->byId(42)->one();
```

`one()` returns the row. It throws `Celema\Quma\Exception\UnexpectedResultCount` when the query returns no row or more than one row.

## Fetch the first row

Use `first()` when the query may return zero or more rows and you only need the first row.

```php
$user = $db->users->byEmail([
    'email' => 'someone@example.com',
])->first();
```

`first()` returns the first row or `null`. It executes the statement fresh on each call, so repeated calls on the same `Query` instance return the same row.

## Fetch successive rows

Use `fetch()` when you want cursor-style access to one row at a time.

```php
$query = $db->users->list();

while (($user = $query->fetch()) !== null) {
    // ...
}
```

`fetch()` returns the next row or `null` when the cursor is exhausted.

## Fetch all rows

Use `all()` to fetch every row at once.

```php
$users = $db->users->list()->all();
```

`all()` executes the statement and returns an array of rows.

## Iterate rows lazily

Use `lazy()` when you want a generator.

```php
foreach ($db->users->list()->lazy() as $user) {
    // ...
}
```

This is useful when you want to process rows one by one.

`lazy()` creates PHP's row arrays one at a time, but on MySQL and PostgreSQL the driver first loads the whole result into memory. Use `stream()` for results too large for that.

## Stream large results

`stream()` lets the server send the rows while the loop runs, so a large result needs the memory of one row on every driver.

```php
foreach ($db->users->list()->stream() as $user) {
    // ...
}
```

It takes the same arguments as `lazy()`. A stream has limits:

- While a stream is open, every other statement through the same `Database` throws `Celema\Quma\Exception\StreamOpen`, and so do `begin()`, `commit()`, `rollback()`, `transaction()`, and `ping()`. Without this guard, PostgreSQL would silently end the stream and lose its remaining rows, and MySQL would refuse the statement. Run them after the loop, use `lazy()`, or use a second `Database` for its own connection.
- Statements run directly on `getConn()` bypass the guard, so do not use it during a stream.
- A stream ends with its last row or when the generator is destroyed, for example by `break` when it is not stored in a variable. On MySQL, ending early still reads the remaining rows, so make the query return only what the loop needs.
- `reset()` and `disconnect()` end an open stream, and the loop then throws `RuntimeException`.
- `len()` after a stream returns the number of rows it read.
- On PostgreSQL, the rows only arrive while the loop runs from PHP 8.5.10. Lazy fetching in `pdo_pgsql` before that version can crash PHP or block the next statement, so `stream()` loads the whole result first, like `lazy()`. The rows and the limits above stay the same.

## Map rows to objects

Pass a class name as the first argument to `one()`, `first()`, `fetch()`, `all()`, or `lazy()` to hydrate rows into typed objects.

```php
final readonly class User
{
    public function __construct(
        public int $id,
        public string $email,
        public ?string $name = null,
    ) {}
}

$user = $db->users->byId(42)->one(User::class);
$users = $db->users->list()->all(User::class);

foreach ($db->users->list()->lazy(User::class) as $user) {
    // $user is a User instance.
}
```

Quma reads public constructor parameters and matches each parameter name to a row column. Missing optional columns use the constructor default. Missing required columns throw `Celema\Quma\Exception\MissingColumn`. Extra row columns are ignored.

Constructor hydration requires a public constructor, declared on the class or inherited. Explicit zero-argument constructors are supported and are responsible for initialization. Classes without a constructor, including empty classes and property-only DTOs, throw `Celema\Quma\Exception\InvalidHydrationTarget` unless they implement `Hydratable`. Quma does not populate properties directly.

Use `#[Column]` when the database column name differs from the constructor parameter name.

```php
use Celema\Quma\Column;

final readonly class User
{
    public function __construct(
        public int $id,
        #[Column('email_address')]
        public string $email,
    ) {}
}
```

Constructor hydration supports these declared types:

- `int`, `float`, `bool`, and `string`
- nullable types such as `?int` and `int|null`
- unions of supported types, such as `int|float`
- `DateTimeImmutable` and `DateTime` from common SQL date/time strings
- backed enums from their backing values

Unsupported constructor shapes or types throw `Celema\Quma\Exception\InvalidHydrationTarget`. Present values that cannot be converted to the declared type throw `Celema\Quma\Exception\InvalidTypeCoercion`. A present `null` never falls back to a default; it must be accepted by the declared type.

### Custom hydration

Implement `Hydratable` when a class owns its row conversion logic.

```php
use Celema\Quma\Hydratable;

final readonly class User implements Hydratable
{
    public function __construct(public int $id, public string $email) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): static
    {
        return new self((int) $row['id'], strtolower((string) $row['email']));
    }
}
```

`Hydratable::fromRow()` receives an associative row and takes precedence over constructor reflection. A class implementing `Hydratable` does not need a constructor; its factory is responsible for constructing and initializing the object.

### Polymorphic hydration

Pass a resolver closure when the target class depends on row data.

```php
$events = $db->events->list()->all(
    static fn(array $row): string => $row['type'] === 'created'
        ? UserCreated::class
        : UserDeleted::class,
);
```

The resolver runs once per hydrated row. It must return an existing class name. Returning `null`, a scalar type name, an unknown class, or a non-hydratable abstract class throws `Celema\Quma\Exception\InvalidHydrationTarget`.

## Run write queries

Use `run()` for statements where you only care whether execution succeeded.

```php
$db->users->delete([
    'id' => 42,
])->run();
```

`run()` returns `bool`.

## Read row counts

Use `len()` to return `PDOStatement::rowCount()`.

```php
$count = $db->users->delete([
    'id' => 42,
])->len();
```

`len()` executes a query only if it has not run yet. Otherwise it returns the count of the latest run, so a write is not repeated:

```php
$query = $db->users->delete(['id' => 42]);
$query->run();
$count = $query->len();
```

Call `run()` to execute a query again. `one()`, `first()`, `all()`, and `lazy()` also execute it again on each call.

The exact row count depends on the PDO driver. For example, SQLite may return `0` for statements where other drivers report an affected row count.

## Fetch modes

Quma uses the fetch mode from `Connection` by default. The default is `PDO::FETCH_ASSOC`.

Unmapped calls to `one()`, `first()`, `fetch()`, `all()`, and `lazy()` support these array-producing modes:

- `PDO::FETCH_ASSOC`: column names as keys
- `PDO::FETCH_NUM`: numeric indexes as keys
- `PDO::FETCH_BOTH`: both column names and numeric indexes as keys
- `PDO::FETCH_NAMED`: column names as keys, with duplicate column names collected into arrays

Other modes, including `PDO::FETCH_OBJ`, `PDO::FETCH_COLUMN`, and `PDO::FETCH_DEFAULT`, and mode flags throw `InvalidArgumentException` before the query executes. This applies to both connection defaults and per-call overrides. For `lazy()`, validation happens when iteration starts. To use other PDO fetch modes, obtain the PDO connection through `Database::getConn()` and prepare and execute the statement directly.

```php
use PDO;

$conn = new Connection(
    'sqlite:' . __DIR__ . '/app.sqlite',
    __DIR__ . '/sql',
)->fetch(PDO::FETCH_NUM);
```

You can also override the fetch mode per call. The fetch mode is the second argument, or the `fetchMode` named argument.

```php
use PDO;

$user = $db->users->byId(42)->one(fetchMode: PDO::FETCH_ASSOC);
$rows = $db->users->list()->all(null, PDO::FETCH_NUM);
```

Mapped calls always fetch associative rows by default, even if the connection default is different. Passing a mapped class or resolver with an explicit non-associative fetch mode throws `InvalidArgumentException`.

## Transactions

`Database::transaction()` runs a function atomically. It commits when the function returns and rolls back when it throws, then rethrows the exception. It returns what the function returns.

```php
$id = $db->transaction(function () use ($db): int {
    $user = $db->users->add(email: 'someone@example.com')->one();
    $db->audit->log(user: $user['id'])->run();

    return $user['id'];
});
```

Inside an open transaction, whether from another block or from `begin()`, a block uses a savepoint. A failure undoes only that block's work and leaves the outer transaction usable, so a function can use a block whether or not its caller has one:

```php
$db->transaction(function () use ($db, $item): void {
    $db->orders->add(item: $item)->run();

    try {
        $db->transaction(fn() => $db->stock->reduce(item: $item)->run());
    } catch (PDOException) {
        $db->orders->backorder(item: $item)->run();
    }
});
```

`commit()` and `rollback()` throw `RuntimeException` inside a block. `begin()` throws there too, because a transaction is already open.

On MySQL, a statement such as `CREATE TABLE` commits implicitly, so the work before it can no longer be undone. The block then ends without a commit of its own. Replacing the connection inside a block, for example with `reconnect()`, makes the block throw.

## Handling failed queries

A failing statement or transaction call throws an exception in the `Celema\Quma\Exception` namespace, so the same failure throws the same class on every driver:

- `IntegrityViolation`, a constraint refused the statement:
  - `UniqueViolation`
  - `ForeignKeyViolation`
  - `NotNullViolation`
  - `CheckViolation`
  - `ExclusionViolation`
- `DataError`, a value does not fit its column or type
- `TransactionConflict`, a deadlock, failed serialization, or lock that could not be taken, so repeating the transaction may succeed
- `ConnectionLost`, the connection broke; [`reset()`](long-running-processes.md) replaces it
- `QueryFailed`, the parent of all of these and the class of any other failure, such as a syntax error

```php
try {
    $db->users->add(email: $email)->run();
} catch (UniqueViolation $e) {
    return 'That address is taken.';
}
```

`$e->script` holds the path of the script file, or `null` for direct SQL and transaction calls. `$e->constraint` holds the name of the violated constraint, if the driver's message has one. On SQLite, a unique or not-null violation names the columns instead, such as `users.email`. It is read from English messages, so it is `null` when PostgreSQL's `lc_messages` selects another language.

All of them extend `PDOException` and keep the driver's message, code, and `errorInfo`.

## Execute direct SQL

`Database::execute()` accepts the SQL string and the same argument styles as file-based queries.

```php
$total = $db->execute(
    'SELECT count(*) AS total FROM users WHERE active = :active',
    active: true,
)->one();
```

The SQL string is the `query` parameter of `execute()`, so pass a value for a `:query` placeholder in an array.

## Observe statements

`Connection::onExecute()` calls a function with a `Celema\Quma\Execution` after every statement, also a failed one. Unlike debug output, it is meant for production, for example to log slow scripts:

```php
$conn = new Connection($dsn, $sql)->onExecute(static function (Execution $execution) use ($log): void {
    if ($execution->duration > 0.5) {
        $log->warning('Slow query', ['script' => $execution->script, 'seconds' => $execution->duration]);
    }
});
```

- An `Execution` holds the script path, the SQL, the duration, the row count, and the error, but never the bound values. Values a `.tpql` template writes into the SQL are part of the SQL, and a driver's error message can quote values.
- An exception the function throws goes to PHP's error log. The statement's result or error reaches your code as usual.
- Statements the function runs on the same `Database` are not reported.
- Prepared and executed statements are reported, including the opening of a stream, whose row count is `null`. `begin()`, `commit()`, `rollback()`, savepoints, connection checks, and fetching rows are not.
- On SQLite and in a stream, the database produces rows while they are fetched, so the duration only covers the start of a slow query.

## Debug interpolated SQL

`Query` implements `__toString()`. Converting it to a string returns an interpolated debug representation of the SQL.

```php
$query = $db->users->byEmail([
    'email' => 'someone@example.com',
]);

$sql = (string) $query;
```

You can also enable debug output without changing application code.

> **⚠ Warning — Development only.** Never enable debug output in production. Interpolated SQL writes real query data to disk or the error log, including secrets, credentials, tokens, and PII. There is no production guard — the debug system activates purely from environment variables.

```bash
QUMA_DEBUG=1 QUMA_DEBUG_PRINT=1 php app.php
QUMA_DEBUG=1 QUMA_DEBUG_INTERPOLATED=/tmp/quma/interpolated php app.php
```

`QUMA_DEBUG` enables debug handling for new `Database` instances when set to `1`, `true`, `yes`, or `on` case-insensitively. Set it before creating the `Database`, then choose one or more output channels. `QUMA_DEBUG_PRINT` writes interpolated SQL to PHP's error log (stderr in the CLI unless `error_log` is configured) when set to a true flag value. `QUMA_DEBUG_TRANSLATED` writes runtime SQL before parameter interpolation, and `QUMA_DEBUG_INTERPOLATED` writes interpolated SQL. Debug files are written below `<dir>/<session>/0001--...`. Add driver or output-type directories to the environment variable value if you want them. HTTP sessions include request time, method, a sanitized URI path, and a short hash. CLI sessions include process start time and a short hash. The four-digit counter preserves query order inside the session.

This interpolation is intended for debugging, not for constructing executable SQL. It can contain secrets or user data, so keep output outside the public web root and do not commit it.
