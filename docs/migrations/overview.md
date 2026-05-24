---
title: Migrations overview
---

# Migrations overview

Quma includes a migration runner for SQL, template, and PHP migrations. It discovers migration files from the directories configured on `Connection`, sorts them by file name, and records applied migrations in a database table.

## Configure migration directories

Configure migration directories with `Connection::migrations()`.

```php
$conn = new Connection(
    'sqlite:' . __DIR__ . '/app.sqlite',
    __DIR__ . '/sql',
)->migrations(__DIR__ . '/migrations');
```

You can also pass a list of directories.

```php
$conn = new Connection(
    'sqlite:' . __DIR__ . '/app.sqlite',
    __DIR__ . '/sql',
)->migrations([
    __DIR__ . '/migrations/core',
    __DIR__ . '/migrations/project',
]);
```

For flat lists, later entries take precedence when Quma resolves the internal directory list.

## Supported migration file types

Quma loads these migration types:

- `.sql` for static SQL
- `.tpql` for PHP-rendered SQL
- `.php` for custom migration logic

Static placeholders are supported in `.sql` and `.tpql` migrations when configured through `Connection::placeholders()`. They are not processed in `.php` migrations.

## Naming and ordering

Quma sorts migrations by file name, not by full path. A timestamp prefix is the easiest way to keep the order clear.

Typical file names look like this:

```text
250320-101500-create-users.sql
250320-101700-add-indexes.tpql
250320-102000-backfill-data.php
```

## Driver-specific migration files

You can scope a migration to one driver by including the driver in brackets in the file name.

```text
250320-103000-fix-defaults-[sqlite].sql
250320-103000-fix-defaults-[pgsql].sql
250320-103000-fix-defaults-[mysql].sql
```

Quma only applies the file that matches the current driver.

## Running migrations from the CLI

Quma exposes a `migrations` command through `Celemas\Quma\Commands::get()`.

Without `--apply`, the command never mutates the database. SQLite and PostgreSQL run the batch inside a transaction and roll it back. MySQL shows a plan only because Quma cannot safely roll back a full MySQL migration batch.

```bash
php your-cli-entry.php db:migrations
```

To actually commit the changes, add `--apply`.

```bash
php your-cli-entry.php db:migrations --apply
```

## Migrations table

Before applying migrations, Quma checks whether the migrations table exists. For supported drivers, the `migrations` command creates it automatically when needed.

By default, the metadata table uses:

- table: `migrations`
- migration column: `migration`
- applied column: `applied`

You can customize these names on `Connection`.

```php
$conn
    ->migrationTable('quma_migrations')
    ->migrationColumns('version', 'executed_at');
```

Quma uses the configured table and column names when it creates the metadata table, reads applied migrations, and records new migrations.

For flat migrations and the `default` namespace, Quma records the migration file base name, for example `250320-101500-create-users.sql`. For non-default namespaces, Quma records `namespace:basename`, for example `billing:250320-101500-create-users.sql`.

## Dry run and transaction behavior

Transaction behavior depends on the driver.

- SQLite: transactional
- PostgreSQL: transactional
- MySQL: non-transactional in Quma's migration runner

For SQLite and PostgreSQL:

- running `migrations` without `--apply` shows what would happen and rolls back
- an error rolls back the whole batch

For MySQL:

- running `migrations` without `--apply` lists the pending migrations and does not execute, render, require, create a metadata table, or record anything
- running `migrations --apply` applies migrations directly because there is no rollback path in the migration runner
- successful migrations remain applied before a later error

## Empty migrations

If a migration file exists but renders or contains only whitespace, Quma skips it and prints a warning.

## SQL migrations

A `.sql` migration is executed directly after static placeholders have been substituted.

```sql
CREATE TABLE /*:prefix:*/users (
    id integer primary key,
    email text not null
);
```

## Template migrations

A `.tpql` migration is a PHP template that must render SQL. Quma renders the template first and then substitutes static placeholders in the rendered SQL, using the configured delimiters.

Inside migration templates, Quma makes these variables available:

- `$driver`
- `$db`
- `$conn`

Example:

```php
<?php if ($driver === 'pgsql') : ?>
ALTER TABLE users ADD COLUMN created_at timestamp with time zone;
<?php else : ?>
ALTER TABLE users ADD COLUMN created_at text;
<?php endif ?>
```

As with query templates, generated placeholder tokens are allowed when they come from trusted migration logic. Keep runtime or external values out of rendered SQL text.

## PHP migrations

A `.php` migration must return a class name that implements `Celemas\Quma\Contract\Migration`.

```php
<?php

declare(strict_types=1);

namespace Quma\Migrations\M250320_102000_CreateExample;

use Celemas\Quma\Contract;
use Celemas\Quma\Environment;

class Migration implements Contract\Migration
{
    public function run(Environment $env): void
    {
        $env->db->execute('CREATE TABLE example (id integer primary key)')->run();
    }
}

return Migration::class;
```

See [PHP migrations](php-migrations.md) for the full interface, factory support, and environment details.

## Namespaces

If you need multiple independent migration sets, use namespaced migration directories. See [Migration namespaces](namespaces.md).
