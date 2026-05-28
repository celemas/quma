---
title: Testing
---

# Testing

Quma runs against SQLite by default. You can also run the test suite against MySQL and PostgreSQL by creating local test databases and setting the matching environment variables.

## Available Composer scripts

```bash
composer test
composer test:sqlite
composer test:mysql
composer test:pgsql
composer test:all
composer coverage
composer types
composer docs:lint
composer ci
```

## Default test behavior

If you do not set `QUMA_TEST_DRIVERS`, the test suite runs SQLite only.

## Environment variables

Use these variables to control the test databases.

- `QUMA_TEST_DRIVERS`: comma-separated list of drivers to test; default `sqlite`; allowed values `sqlite`, `mysql`, `pgsql`
- `QUMA_DB_SQLITE_DB_PATH_1`: primary SQLite file name; default `quma_db1.sqlite3`
- `QUMA_DB_SQLITE_DB_PATH_2`: secondary SQLite file name; default `quma_db2.sqlite3`
- `QUMA_DB_PGSQL_HOST`: PostgreSQL host; default `localhost`
- `QUMA_DB_MYSQL_HOST`: MySQL host; default `127.0.0.1`
- `QUMA_DB_NAME`: database name; default `quma`
- `QUMA_DB_USER`: database user; default `quma`
- `QUMA_DB_PASSWORD`: database password; default `quma`

Example:

```bash
export QUMA_DB_PGSQL_HOST=192.168.1.100
export QUMA_DB_USER=testuser
export QUMA_DB_PASSWORD=testpass
export QUMA_TEST_DRIVERS=sqlite,mysql,pgsql
composer test
```

## PostgreSQL test database

The examples below use the default values from the test suite.

Using the CLI:

```bash
echo "quma" | createuser --pwprompt --createdb quma
createdb --owner quma quma
```

Using SQL:

```sql
CREATE ROLE quma WITH LOGIN PASSWORD 'quma' CREATEDB;
CREATE DATABASE quma WITH OWNER quma;
```

## MySQL or MariaDB test database

Using the CLI:

```bash
mysql -u root -p -e "CREATE DATABASE quma; CREATE USER 'quma'@'localhost' IDENTIFIED BY 'quma'; GRANT ALL PRIVILEGES ON quma.* TO 'quma'@'localhost';"
```

Using SQL:

```sql
CREATE DATABASE quma;
CREATE USER 'quma'@'localhost' IDENTIFIED BY 'quma';
GRANT ALL PRIVILEGES ON quma.* TO 'quma'@'localhost';
```

## Notes about host defaults

The MySQL default host is `127.0.0.1`, not `localhost`. The test suite does this intentionally because PDO MySQL may try to use a local socket for `localhost`, which is not suitable for every setup.
