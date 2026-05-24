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

Without `--apply` or `--test-run`, the command is plan-only for every driver. It lists pending migrations and exits without executing SQL migrations, rendering `.tpql` migrations, requiring `.php` migrations, creating the metadata table, or recording anything.

```bash
php run db:migrations
```

To run the migrations inside a rollback transaction on SQLite or PostgreSQL, use `--test-run --yes`.

```bash
php run db:migrations --test-run --yes
```

To actually commit the changes, add `--apply`.

```bash
php run db:migrations --apply
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

## Plan, test run, and transaction behavior

Transaction behavior depends on the mode and driver.

- Plan mode: `migrations` without `--apply` or `--test-run`; no migrations are executed on any driver
- Test run: `migrations --test-run`; SQLite and PostgreSQL only; executes inside a transaction and rolls back
- Apply: `migrations --apply`; commits the migrations and records them

For plan mode:

- Quma lists pending migrations only
- Quma does not execute SQL migrations
- Quma does not render `.tpql` migrations
- Quma does not require or run `.php` migrations
- Quma does not create the metadata table or record anything

For SQLite and PostgreSQL test runs:

- `migrations --test-run` requires interactive confirmation, or `--yes` in non-interactive shells
- pending migrations execute inside a transaction and roll back at the end
- an error rolls back the whole batch
- `.sql` migrations are sent to the database during the test run
- `.tpql` migrations are rendered, so any PHP code in the template runs
- `.php` migrations are required and their `run()` method is called
- rollback does not undo file writes, HTTP calls, queue jobs, emails, logs, cache writes, or other non-database side effects

Examples:

- `php run db:migrations` only lists pending migrations.
- `php run db:migrations --test-run --yes` executes pending migrations on SQLite or PostgreSQL, then rolls the transaction back.
- `CREATE TABLE users (...)` is normally rolled back on SQLite and PostgreSQL when the test run finishes.
- A `.php` migration that writes `var/export.csv`, calls an API, or dispatches a job does that during a test run.
- Some PostgreSQL statements cannot run inside a transaction, for example `CREATE INDEX CONCURRENTLY`, `CREATE DATABASE`, or `VACUUM`. Such migrations may fail during `--test-run` because Quma wraps the batch in a transaction.

For MySQL:

- `migrations` without flags is plan-only and does not execute, render, require, create a metadata table, or record anything
- `migrations --test-run` is refused because many MySQL DDL statements, for example `CREATE TABLE`, `ALTER TABLE`, and `DROP TABLE`, cause implicit commits and cannot be safely rolled back
- `migrations --apply` applies migrations directly because there is no rollback path in the migration runner
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
