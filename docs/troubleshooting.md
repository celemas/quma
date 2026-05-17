---
title: Troubleshooting
---

# Troubleshooting

This page collects the most common runtime errors and a few current limitations that are visible from the codebase.

## `The SQL folder does not exist: ...`

Quma could not resolve the folder that you accessed through `Database`.

Check:

- that the folder exists under one of the configured SQL directories
- that the SQL directory path passed to `Connection` is correct
- that driver-specific directory rules resolve the folder for the current driver

## `SQL script does not exist`

Quma found the folder but not the query file.

Check:

- that the file name matches the method name exactly
- that the file ends in `.sql` or `.tpql`
- that the file is present in the highest-priority directory for the current driver

## `Invalid SQL folder name: ...` or `Invalid SQL script name: ...`

Folder and script names must be single path segments. Quma rejects empty names, `.`, `..`, path separators, and NUL bytes before resolving SQL files.

Use `$db->users->byId()` instead of deriving folder or script names from untrusted input.

## `Path does not exist: ...`

`Connection` validates SQL and migration paths up front.

Create the directory first or fix the configured path before constructing `Connection`.

## `PDO driver not supported: ...`

The DSN prefix does not match an available PDO driver.

Check:

- the DSN prefix, such as `sqlite:`, `mysql:`, or `pgsql:`
- that the matching PDO extension is installed and enabled

## `Expected exactly one result, got ...`

`Query::one()` requires exactly one row. It throws `UnexpectedResultCountException` when the query returns no rows or more than one row.

Use `first()` if zero or multiple rows are valid and you only need the first row. Use `fetch()` if you want to read successive rows from the same query cursor.

## Template query rejects positional arguments

`.tpql` query files accept named parameters only.

Use:

```php
$db->users->listActive([
    'active' => true,
]);
```

Do not use:

```php
$db->users->listActive(true);
```

## MySQL dry runs only print a plan

MySQL migrations are not transactional in Quma. When you run `db:migrations` without `--apply` on MySQL, Quma lists pending migrations and exits without executing migrations, rendering templates, requiring PHP migrations, creating the metadata table, or recording anything.

Use `--apply` to run MySQL migrations.

## `No migration directories defined in configuration`

The migration command was started without any configured migration directories.

Call `Connection::migrations()` or configure namespaced migrations with `Connection::migrationNamespace()`.

## `Migration namespace '...' does not exist`

You passed `--namespace` with a name that is not present in the connection's migration configuration.

Check the namespace keys in your `Connection` setup.

## `Migration namespace 'default' does not exist`

You are using namespaced migrations and ran the migration command without `--namespace`, but your configuration does not define `default`.

Fix one of these:

- add a `default` namespace
- run the command with `--namespace <name>`

## `No migration directories configured. Aborting.`

The `db:add-migration` command needs at least one migration directory. Configure migrations on `Connection` before using the command.

## `No valid migration directory found. Aborting.`

The migration config exists, but the first namespace or directory entry does not resolve to a writable directory that the command can use.

## `The migrations directory is inside './vendor'.`

Quma intentionally refuses to create migration files inside `vendor`.

Move your migrations into an application-owned directory.

## `Migrations directory is not writable`

The target migration directory exists but is not writable by the current process.

Fix the filesystem permissions or choose another directory.
