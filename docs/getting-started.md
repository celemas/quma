---
title: Getting started
---

# Getting started

This guide shows the smallest useful Quma setup: one connection, one SQL directory, and a few query files.

## Install the package

```bash
composer require celema/quma
```

Quma requires PHP 8.5 or newer with `ext-json`, `ext-pdo`, and `ext-readline` enabled.

## Create a SQL directory

Quma reads query files from directories that you configure on `Connection`.

A minimal layout looks like this:

```text
sql/
  users/
    byId.sql
    byEmail.sql
    list.sql
```

Add a few queries:

```sql
-- sql/users/byId.sql
SELECT id, email, created_at FROM users WHERE id = ?;
```

```sql
-- sql/users/byEmail.sql
SELECT id, email, created_at FROM users WHERE email = :email;
```

```sql
-- sql/users/list.sql
SELECT id, email, created_at FROM users ORDER BY id;
```

## Configure a connection

Create a `Connection` with a PDO DSN and at least one SQL directory.

```php
<?php

declare(strict_types=1);

use Celema\Quma\Connection;

$conn = new Connection(
    'sqlite:' . __DIR__ . '/app.sqlite',
    __DIR__ . '/sql',
)->migrations(__DIR__ . '/migrations');
```

The `migrations()` call configures migration directories. If you do not use migrations yet, you can omit it.

## Create a database handle

Wrap the connection in `Database`.

```php
<?php

declare(strict_types=1);

use Celema\Quma\Database;

$db = new Database($conn);
```

`Database` lazily opens the PDO connection the first time you execute a query.

## Run queries from files

Quma maps folders to properties and files to methods.

- `sql/users/byId.sql` becomes `$db->users->byId()`
- `sql/users/byEmail.sql` becomes `$db->users->byEmail()`
- `sql/users/list.sql` becomes `$db->users->list()`

Use positional parameters:

```php
$user = $db->users->byId(42)->one();
```

Use named parameters:

```php
$user = $db->users->byEmail([
    'email' => 'someone@example.com',
])->one();
```

Fetch all rows:

```php
$users = $db->users->list()->all();
```

## Execute ad-hoc SQL

You can also execute SQL strings directly without creating a file first.

```php
$total = $db->execute('SELECT count(*) AS total FROM users')->one();
```

`Database::execute()` returns the same `Query` object that file-based queries use.

## Choose the next guide

After the quickstart, continue with:

1. [Query files](query-files.md)
2. [Parameters and results](parameters-and-results.md)
3. [Templates](templates.md)
4. [Migrations overview](migrations/overview.md)
