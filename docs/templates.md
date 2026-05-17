---
title: Templates
---

# Templates

Quma supports dynamic SQL through `.tpql` files. A template query is a PHP file that renders SQL and then runs that SQL as a prepared statement.

## When to use templates

Use a template when the SQL shape itself needs to change, for example:

- optional columns
- optional `WHERE` clauses
- driver-specific fragments
- configurable ordering

If the SQL text is static and only the values change, prefer a normal `.sql` file.

## Create a template query

Store the query as `<name>.tpql` instead of `<name>.sql`.

```php
SELECT id, email
FROM users
WHERE active = :active
<?php if (($order ?? 'asc') === 'desc') : ?>
ORDER BY email DESC
<?php else : ?>
ORDER BY email ASC
<?php endif ?>
```

Quma looks for `.sql` first and `.tpql` second. If there is no matching `.sql` file, it loads the `.tpql` template.

## Call a template query

Template queries accept named parameters only.

```php
$users = $db->users->listActive([
    'active' => true,
    'order' => 'desc',
])->all();
```

If you pass positional arguments to a template query, Quma throws `InvalidArgumentException`.

## Variables available inside templates

Quma injects these values into a query template:

- `$pdodriver` with the active PDO driver name
- every key from your named parameter array

Example:

```php
SELECT id, email, '<?= $pdodriver ?>' AS driver
FROM users
WHERE active = :active
```

## Static placeholders in templates

`.tpql` files can use static `/*:name:*/` placeholders in the literal SQL part of the template when placeholders are configured on `Connection`.

```php
SELECT id, email
FROM /*:prefix:*/users
<?php if (($activeOnly ?? false) === true) : ?>
WHERE active = :active
<?php endif ?>
```

Quma applies static placeholders before it renders the PHP template. The rendered SQL then goes through normal PDO preparation and parameter binding. Use the delimiter syntax passed to `Connection::placeholders()` in the literal SQL part of the template. If placeholders are not configured, Quma leaves template source unchanged.

Do not put static placeholders inside PHP code blocks or generate them from PHP. This is unsupported and Quma throws a clear exception if a rendered template still contains a configured static placeholder token. Move the static placeholder into the literal SQL portion of the template, or use trusted PHP configuration directly.

## Cache template queries

By default, Quma writes compiled template source to a temporary file for each `.tpql` query invocation and requires that file. This keeps configuration simple, but it adds filesystem work for hot template queries.

For hot `.tpql` query files, configure a cache directory on `Connection`.

```php
$conn->cache(__DIR__ . '/var/cache/quma');
```

The directory must already exist and be writable. Keep it outside the public web root because it contains generated PHP template files.

When a cache directory is configured, Quma writes each compiled `.tpql` query template once per cache key and requires that cached file on later invocations. The cache key includes the source path, source metadata, active driver, and static placeholder configuration when enabled. If the template source or placeholder configuration changes, Quma creates a new cache file.

You can safely delete Quma cache files; they are regenerated on demand. The cache only applies to `.tpql` query files. It does not apply to migrations or direct SQL passed to `Database::execute()`.

## Unused parameters are stripped

PDO rejects named parameters that are bound but not used in the SQL. Quma accounts for that by removing named parameters that do not appear as placeholders in the rendered template.

That means you can safely pass control values that only affect template rendering.

```php
$users = $db->users->listActive([
    'active' => true,
    'order' => 'desc',
])->all();
```

In this example, `order` can control the rendered SQL without needing a matching `:order` placeholder.

## Driver-specific SQL in templates

You can branch on `$pdodriver` inside the template.

```php
SELECT id, email
FROM users
<?php if ($pdodriver === 'pgsql') : ?>
ORDER BY email NULLS LAST
<?php else : ?>
ORDER BY email
<?php endif ?>
```

## Keep template output valid SQL

Quma executes the rendered output as a prepared statement, so the final template output must be valid SQL for the current driver.

A good pattern is:

- use PHP only to include or exclude SQL fragments
- keep placeholders in the SQL itself
- keep values in bound parameters instead of string concatenation

## Debug rendered templates

Template queries return the same `Query` object as static SQL files, so you can still inspect the interpolated SQL for debugging.

```php
$query = $db->users->listActive([
    'active' => true,
    'order' => 'desc',
]);

echo (string) $query;
```

The string output is for debugging only.
