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

Use one associative array for named placeholders.

```sql
SELECT id, email FROM users WHERE email = :email;
```

```php
$user = $db->users->byEmail([
    'email' => 'someone@example.com',
])->one();
```

## Supported parameter types

Quma supports these parameter value types:

- `bool`
- `int`
- `string`
- `null`
- `array`

Arrays are JSON-encoded before binding.

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

## Stream rows lazily

Use `lazy()` when you want a generator.

```php
foreach ($db->users->list()->lazy() as $user) {
    // ...
}
```

This is useful when you want to process rows one by one.

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

`Hydratable::fromRow()` receives an associative row and takes precedence over constructor reflection.

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

The exact row count depends on the PDO driver. For example, SQLite may return `0` for statements where other drivers report an affected row count.

## Fetch modes

Quma uses the fetch mode from `Connection` by default. The default is `PDO::FETCH_ASSOC`.

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

## Execute direct SQL

`Database::execute()` accepts the SQL string and the same argument styles as file-based queries.

```php
$total = $db->execute(
    'SELECT count(*) AS total FROM users WHERE active = :active',
    ['active' => true],
)->one();
```

## Debug interpolated SQL

`Query` implements `__toString()`. Converting it to a string returns an interpolated debug representation of the SQL.

```php
$query = $db->users->byEmail([
    'email' => 'someone@example.com',
]);

$sql = (string) $query;
```

You can also enable debug output without changing application code.

> **⚠ Warning — Development only.** Never enable debug output in production. Interpolated SQL writes real query data to disk or stdout, including secrets, credentials, tokens, and PII. There is no production guard — the debug system activates purely from environment variables.

```bash
QUMA_DEBUG=1 QUMA_DEBUG_PRINT=1 php app.php
QUMA_DEBUG=1 QUMA_DEBUG_INTERPOLATED=/tmp/quma/interpolated php app.php
```

`QUMA_DEBUG` enables debug handling for new `Database` instances when set to `1`, `true`, `yes`, or `on` case-insensitively. Set it before creating the `Database`, then choose one or more output channels. `QUMA_DEBUG_PRINT` prints interpolated SQL when set to a true flag value. `QUMA_DEBUG_TRANSLATED` writes runtime SQL before parameter interpolation, and `QUMA_DEBUG_INTERPOLATED` writes interpolated SQL. Debug files are written below `<dir>/<session>/0001--...`. Add driver or output-type directories to the environment variable value if you want them. HTTP sessions include request time, method, a sanitized URI path, and a short hash. CLI sessions include process start time and a short hash. The four-digit counter preserves query order inside the session.

This interpolation is intended for debugging, not for constructing executable SQL. It can contain secrets or user data, so keep output outside the public web root and do not commit it.
