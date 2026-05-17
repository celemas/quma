---
title: Introduction
---

# Quma

Quma is a no-ORM database library for PHP. You keep SQL in files, execute it through a small PDO-backed API, and can layer on PHP-powered templates and migrations when plain SQL is not enough.

## Who it is for

Quma fits projects that want to:

- keep SQL visible instead of hiding it behind an ORM
- organize queries by feature or table in folders
- support multiple PDO drivers with a shared query layout
- ship database migrations alongside application code

## Core concepts

Quma revolves around a small set of objects:

- `Connection` holds the DSN, SQL directories, migration directories, and fetch mode.
- `Database` opens the PDO connection and is the main entry point.
- `Folder` maps a SQL folder such as `users/` to `$db->users`.
- `Script` wraps a `.sql` or `.tpql` file.
- `Query` binds parameters, executes the prepared statement, and can hydrate rows into typed objects.

In practice, a file like `sql/users/byId.sql` becomes `$db->users->byId()`.

## Documentation map

Start here, then move from the guides into the reference pages.

### Guides

- [Getting started](getting-started.md)
- [Query files](query-files.md)
- [Parameters and results](parameters-and-results.md)
- [Templates](templates.md)
- [CLI](cli.md)

### Migrations

- [Migrations overview](migrations/overview.md)
- [Migration namespaces](migrations/namespaces.md)
- [PHP migrations](migrations/php-migrations.md)

### Reference

- [Connection reference](reference/connection.md)
- [API reference](reference/api.md)
- [Testing](testing.md)
- [Troubleshooting](troubleshooting.md)

## Feature overview

Quma currently supports:

- static SQL files with positional or named parameters
- explicit static `/*:name:*/` placeholders for trusted driver-aware configuration fragments
- PHP-rendered SQL templates in `.tpql` files
- multiple SQL directories with driver-specific overrides
- direct execution of ad-hoc SQL through `Database::execute()`
- optional type-safe row hydration through `one()`, `first()`, `fetch()`, `all()`, and `lazy()`
- explicit database lifecycle helpers such as `connected()`, `disconnect()`, `reconnect()`, `ping()`, and `reset()` for long-running PHP processes
- migrations written in `.sql`, `.tpql`, or `.php`
- environment-controlled debug output for translated and interpolated SQL
- CLI helpers for creating and applying migrations

## Suggested reading order

If you are new to the library, read the pages in this order:

1. [Getting started](getting-started.md)
2. [Query files](query-files.md)
3. [Parameters and results](parameters-and-results.md)
4. [Templates](templates.md)
5. [Migrations overview](migrations/overview.md)
