---
title: Migration namespaces
---

# Migration namespaces

Migration namespaces let you keep multiple independent migration sets in one application. This is useful for modular systems, optional features, or install flows that should not always run together.

## Flat vs namespaced configuration

A flat migration configuration looks like this:

```php
$conn = new Connection(
    'sqlite:' . __DIR__ . '/app.sqlite',
    __DIR__ . '/sql',
)->migrations(__DIR__ . '/migrations');
```

Quma treats flat migration directories as one namespace named `default`.

A namespaced configuration looks like this:

```php
$conn = new Connection(
    'sqlite:' . __DIR__ . '/app.sqlite',
    __DIR__ . '/sql',
)->migrations([
    'default' => [__DIR__ . '/migrations/core'],
    'feature' => [__DIR__ . '/migrations/feature'],
    'install' => __DIR__ . '/migrations/install',
]);
```

In a namespaced setup, each key becomes the namespace name.

## Running a namespace

Use `--namespace` with the `migrations` command.

```bash
php run db:migrations --namespace=feature --apply
```

Quma loads only the files from that namespace.

When Quma records applied migrations, non-default namespaces are part of the stored migration ID. For example, `feature/250320-101500-create-users.sql` is recorded as `feature:250320-101500-create-users.sql`. The `default` namespace keeps the plain file base name for compatibility.

## The special `default` namespace

If you do not pass `--namespace`, Quma expects a namespace named `default`.

That means:

- flat migration configs work automatically because Quma wraps them as `default`
- namespaced configs must define `default` if you want `db:migrations` without `--namespace`

If you omit `default` and also omit `--namespace`, Quma aborts and tells you to either use `--namespace` or add a `default` namespace.

## Directory value formats

Inside a namespace, you can use:

- one directory string
- a list of directories
- nested driver-specific entries such as `sqlite` and `all`

Example:

```php
$conn = new Connection(
    'sqlite:' . __DIR__ . '/app.sqlite',
    __DIR__ . '/sql',
)->migrations([
    'default' => [
        [
            'sqlite' => __DIR__ . '/migrations/sqlite',
            'all' => __DIR__ . '/migrations/common',
        ],
    ],
]);
```

## Ordering within namespaces

Quma collects migration files from all directories in the selected namespace and then sorts the files by base name.

Use consistent timestamp prefixes to keep the order predictable. File names only need to be unique within one namespace. Non-default namespaces are recorded with a namespace prefix, so two namespaces can use the same file base name without blocking each other.

## Invalid namespaces

If you run a namespace that does not exist, Quma stops with an error.

```bash
php run db:migrations --namespace=missing --apply
```

## Adding migration directories later

`Connection::addMigration()` only works for flat migration configurations. If the connection uses namespaced migrations, it throws `ValueError`.

Use `Connection::migrationNamespace()` to add or replace one namespaced migration entry after construction.

## Recommended layout

A practical structure looks like this:

```text
migrations/
  core/
  install/
  billing/
  reporting/
```

Then map those folders to namespaces in `Connection`.
