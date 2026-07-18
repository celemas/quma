# Celema Quma

<!-- prettier-ignore-start -->
[![ci](https://codeberg.org/celema/quma/badges/workflows/ci.yml/badge.svg?style=flat&logo=codeberg&logoColor=white&label=ci)](https://codeberg.org/celema/quma/actions)
[![code coverage](https://img.shields.io/endpoint?url=https%3A%2F%2Fcov.celema.dev%2Fcelema%2Fquma%2Fcode%2Fbadge.json)](https://cov.celema.dev/celema/quma/code)
[![type coverage](https://img.shields.io/endpoint?url=https%3A%2F%2Fcov.celema.dev%2Fcelema%2Fquma%2Ftypes%2Fbadge-cover.json)](https://cov.celema.dev/celema/quma/types)
[![psalm level](https://img.shields.io/endpoint?url=https%3A%2F%2Fcov.celema.dev%2Fcelema%2Fquma%2Ftypes%2Fbadge-level.json)](https://cov.celema.dev/celema/quma/types)
[![Software License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)
<!-- prettier-ignore-end -->

Quma is a no-ORM database library for PHP. You store SQL in files, group those files in folders, and execute them through a small PDO-backed API. Quma also ships with template queries and a migration runner.

## Requirements

Quma currently requires:

- PHP 8.5 or newer
- `ext-json`
- `ext-pdo`
- `ext-readline`

## Install

```bash
composer require celema/quma
```

## Quickstart

Create a SQL directory structure like this:

```text
sql/
  users/
    byId.sql
    list.sql
```

Add a query file:

```sql
SELECT id, email FROM users WHERE id = ?;
```

Then configure Quma and run the query:

```php
<?php

declare(strict_types=1);

use Celema\Quma\Connection;
use Celema\Quma\Database;

$conn = new Connection(
    'sqlite:' . __DIR__ . '/app.sqlite',
    __DIR__ . '/sql',
)->migrations(__DIR__ . '/migrations');

$db = new Database($conn);

$user = $db->users->byId(1)->one();
$users = $db->users->list()->all();
```

Quma maps directories to properties and files to methods:

- `sql/users/byId.sql` becomes `$db->users->byId()`
- `sql/users/list.sql` becomes `$db->users->list()`

## What Quma provides

- SQL-file based queries with positional or named parameters
- explicit static `/*:name:*/` placeholders for trusted driver-aware configuration fragments
- PDO-backed execution with exact `one()`, stable `first()`, cursor-style `fetch()`, `all()`, `lazy()`, `run()`, and `len()`
- optional row hydration into typed objects
- PHP-powered SQL templates via `.tpql` files
- multiple SQL directories with driver-specific overrides
- migration commands for `.sql`, `.tpql`, and `.php` migrations
- environment-controlled debug output for translated and interpolated SQL

## Documentation

Start with the docs in [`docs/index.md`](docs/index.md).

Recommended pages:

- [Getting started](docs/getting-started.md)
- [Query files](docs/query-files.md)
- [Parameters and results](docs/parameters-and-results.md)
- [Templates](docs/templates.md)
- [Migrations overview](docs/migrations/overview.md)
- [CLI](docs/cli.md)
- [Testing](docs/testing.md)

## Testing

Quma runs against SQLite by default and can also run against MySQL and PostgreSQL when you provide test databases.

```bash
composer test
composer test:sqlite
composer test:mysql
composer test:pgsql
composer test:all
```

For database setup and environment variables, see [docs/testing.md](docs/testing.md).

## License

This project is licensed under the [MIT license](LICENSE.md).
