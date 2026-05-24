---
title: PHP migrations
---

# PHP migrations

Use a PHP migration when SQL alone is not enough. A PHP migration file must declare or reference a migration class and return that class name.

## Contract

A PHP migration file must return the class name of a class that implements `Celemas\Quma\Contract\Migration`.

The interface is:

```php
<?php

declare(strict_types=1);

namespace Celemas\Quma\Contract;

use Celemas\Quma\Environment;

interface Migration
{
    public function run(Environment $env): void;
}
```

## Minimal example

```php
<?php

declare(strict_types=1);

namespace Quma\Migrations\M250320_102000_CreateUsers;

use Celemas\Quma\Contract;
use Celemas\Quma\Environment;

class Migration implements Contract\Migration
{
    public function run(Environment $env): void
    {
        $env->db->execute(
            'CREATE TABLE users (id integer primary key, email text not null)',
        )->run();
    }
}

return Migration::class;
```

The namespace keeps the default `Migration` class name unique across migration files. You can use another class name if you prefer.

## Constructor dependency injection

Without a migration factory, Quma instantiates the returned class with no constructor arguments.

```php
$migration = new $class();
```

If your migration has required constructor arguments, configure a `Celemas\Quma\Contract\MigrationFactory` when creating the Quma commands. Quma passes the returned class name and the active `Environment` to the factory.

```php
use Celemas\Quma\Contract\Migration;
use Celemas\Quma\Environment;

interface MigrationFactory
{
    /** @param class-string<Migration> $class */
    public function create(string $class, Environment $env): Migration;
}
```

Quma does not depend on a container package. Applications can use any factory implementation.

### Wire example

Install `celemas/wire` in the application if you want autowiring.

```php
use Celemas\Quma\Connection;
use Celemas\Quma\Contract\Migration;
use Celemas\Quma\Contract\MigrationFactory;
use Celemas\Quma\Commands;
use Celemas\Quma\Database;
use Celemas\Quma\Environment;
use Celemas\Wire\Wire;
use Psr\Container\ContainerInterface;
use RuntimeException;

final class WireMigrationFactory implements MigrationFactory
{
    public function __construct(
        private ?ContainerInterface $container = null,
    ) {}

    /** @param class-string<Migration> $class */
    public function create(string $class, Environment $env): Migration
    {
        $migration = Wire::creator($this->container)->create(
            $class,
            predefinedTypes: [
                Environment::class => $env,
                Connection::class => $env->conn,
                Database::class => $env->db,
            ],
        );

        if (!$migration instanceof Migration) {
            throw new RuntimeException("Migration {$class} must implement " . Migration::class);
        }

        return $migration;
    }
}

$commands = Commands::get(
    $conn,
    migrationFactory: new WireMigrationFactory($container),
);
```

`celemas/container` works with this example because it implements PSR-11 and uses Wire for autowiring.

## Autoloading

Quma loads the migration file before it asks the factory to create the migration. If the migration class is declared in that file, no Composer autoload entry is needed for the migration class itself.

Constructor dependencies and migration classes that live outside the migration file must be autoloadable.

## Environment object

Quma passes one `Environment` instance into `run()`.

The migration can read these public properties:

- `$env->conn` as the active `Connection`
- `$env->db` as the active `Database`
- `$env->driver` as the PDO driver name
- `$env->showStacktrace` as the CLI stacktrace flag
- `$env->table` as the migrations table name
- `$env->columnMigration` as the migration name column
- `$env->columnApplied` as the applied-at column
- `$env->options` as the options array passed into the command setup

## Driver-specific logic

A PHP migration can branch on the current driver.

```php
<?php

declare(strict_types=1);

namespace Quma\Migrations\M250320_103000_AddCreatedAt;

use Celemas\Quma\Contract;
use Celemas\Quma\Environment;

class Migration implements Contract\Migration
{
    public function run(Environment $env): void
    {
        switch ($env->driver) {
            case 'sqlite':
                $env->db->execute('ALTER TABLE users ADD COLUMN created_at text')->run();
                break;

            case 'pgsql':
                $env->db->execute(
                    'ALTER TABLE users ADD COLUMN created_at timestamp with time zone',
                )->run();
                break;

            case 'mysql':
                $env->db->execute('ALTER TABLE users ADD COLUMN created_at timestamp')->run();
                break;
        }
    }
}

return Migration::class;
```

## When to choose PHP over SQL or TPQL

Prefer a `.php` migration when you need to:

- run conditional logic that is easier to express in PHP than in templated SQL
- inspect existing data before choosing the next step
- execute several database operations with intermediate checks
- branch heavily by driver

If you only need to include or exclude a few SQL fragments, a `.tpql` migration is usually simpler.

## Failure behavior

If a PHP migration throws, the migration run stops.

For SQLite and PostgreSQL, Quma rolls back the surrounding database transaction on failure and during `--test-run`. That rollback only covers database work in the active transaction. Non-database side effects from PHP code, such as file writes, HTTP calls, queue jobs, emails, logs, and cache writes, are not undone.

The default `migrations` command without `--apply` or `--test-run` is plan-only and does not require or run PHP migrations.

For MySQL, already applied migrations remain applied because the migration runner does not wrap MySQL migrations in the same transaction flow.
