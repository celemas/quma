---
title: CLI
---

# CLI

Quma ships with migration-related commands that plug into `celemas/cli`.

## Register the commands

Use `Celemas\Quma\Commands::get()` to build the command set.

```php
<?php

declare(strict_types=1);

use Celemas\Cli\Runner;
use Celemas\Quma\Commands;
use Celemas\Quma\Connection;

$conn = new Connection(
    'sqlite:' . __DIR__ . '/app.sqlite',
    __DIR__ . '/sql',
)->migrations(__DIR__ . '/migrations');

$runner = new Runner(Commands::get($conn));
exit($runner->run());
```

You can pass either:

- one `Connection`
- an array of named `Connection` objects

## Available commands

Quma registers these commands:

- `db:migrations`
- `db:create-migrations-table`
- `db:add-migration`

## `db:migrations`

Applies missing migrations from the configured migration directories.

```bash
php run db:migrations --apply
```

Options:

- `--apply` runs and records pending migrations
- `--namespace <name>` runs only one migration namespace
- `--stacktrace` prints stack traces for migration failures
- `--conn <name>` selects one named connection when you registered multiple connections

Without `--apply`, behavior depends on the driver. It is not side-effect-free for every driver:

- SQLite and PostgreSQL execute the batch inside a transaction and roll it back at the end. SQL changes from transactional statements are undone, but migration code still runs. A `.tpql` template is rendered and a `.php` migration is required and executed, so file writes, HTTP calls, queue jobs, emails, logs, and other external effects are not rolled back.
- MySQL lists the pending migrations and a rollback warning without executing, rendering, requiring, creating a metadata table, or recording anything. Quma uses plan-only behavior for MySQL because many DDL statements cause implicit commits and cannot be safely rolled back.

## `db:create-migrations-table`

Creates the migrations table explicitly.

```bash
php run db:create-migrations-table
```

The `db:migrations` command already creates the table automatically when possible, so you only need this command when you want to set it up ahead of time.

Options:

- `--stacktrace`
- `--conn <name>`

## `db:add-migration`

Creates a new migration file in the first configured migration directory.

```bash
php run db:add-migration --file create-users.sql
```

Options:

- `-f <name>` or `--file <name>` sets the file name
- `--conn <name>` selects one named connection

If you omit the extension, Quma creates a `.sql` file.

Supported extensions are:

- `.sql`
- `.tpql`
- `.php`

Quma normalizes the file name to lowercase kebab-case and prefixes it with a timestamp in `ymd-His` format.

## Multiple connections

When you pass an array of connections to `Commands::get()`, each key becomes a selectable connection name.

```php
$commands = Commands::get([
    'default' => $defaultConnection,
    'reporting' => $reportingConnection,
]);
```

Select one at runtime:

```bash
php run db:migrations --conn reporting --apply
```

If the selected connection does not exist, Quma throws a runtime error.

## Exit behavior

The commands return `0` on success and `1` on failure.

`db:add-migration` returns the created file path from the command implementation, but when you run it through a CLI runner you should still treat the command as a file-creation command rather than a general-purpose API.
