<?php

declare(strict_types=1);

namespace Celema\Quma;

use Closure;

/**
 * @api
 *
 * @psalm-import-type SqlConfig from Config
 * @psalm-import-type PlaceholderConfig from Config
 */
class Connection
{
	public readonly Config $config;

	/** @var (Closure(Execution): void)|null */
	public private(set) ?Closure $executionHook = null;

	/** @var (Closure(Environment, string): void)|null */
	public private(set) ?Closure $beforeMigrationsHook = null;

	/** @psalm-param SqlConfig $sql */
	public function __construct(string $dsn, string|array $sql)
	{
		$this->config = new Config($dsn, $sql);
	}

	public function credentials(
		string $username,
		#[\SensitiveParameter]
		?string $password = null,
	): static {
		$this->config->setCredentials($username, $password);

		return $this;
	}

	/** @param array<array-key, mixed> $options */
	public function options(array $options): static
	{
		$this->config->setPdoOptions($options);

		return $this;
	}

	public function option(int $attribute, mixed $value): static
	{
		$this->config->setPdoOption($attribute, $value);

		return $this;
	}

	public function fetch(int $fetchMode): static
	{
		$this->config->setFetchMode($fetchMode);

		return $this;
	}

	/**
	 * Calls the hook after every statement, also a failed one, for example to
	 * find slow scripts. An exception it throws is logged and does not affect
	 * the statement. Statements it runs itself are not reported.
	 *
	 * @param Closure(Execution): void $hook
	 */
	public function onExecute(Closure $hook): static
	{
		$this->executionHook = $hook;

		return $this;
	}

	/**
	 * Before a connection that was idle for at least this many seconds is
	 * used again, it is pinged and replaced if it is broken. 0 disables the
	 * check. Default: 60.
	 */
	public function pingAfterIdle(int $seconds): static
	{
		$this->config->setPingAfterIdle($seconds);

		return $this;
	}

	/**
	 * A connection older than this many seconds is replaced before it is
	 * used again. 0 keeps connections regardless of their age (default).
	 */
	public function maxConnectionAge(int $seconds): static
	{
		$this->config->setMaxConnectionAge($seconds);

		return $this;
	}

	/** @psalm-param PlaceholderConfig $placeholders */
	public function placeholders(Delimiters $delimiters, array $placeholders): static
	{
		$this->config->setPlaceholders($delimiters, $placeholders);

		return $this;
	}

	public function migrationTable(string $table): static
	{
		$this->config->setMigrationsTable($table);

		return $this;
	}

	public function migrationColumns(string $migration, string $applied = 'applied'): static
	{
		$this->config->setMigrationsColumnMigration($migration);
		$this->config->setMigrationsColumnApplied($applied);

		return $this;
	}

	/** @psalm-param SqlConfig $migrations */
	public function migrations(array|string $migrations): static
	{
		$this->config->setMigrations($migrations);

		return $this;
	}

	/** @param non-empty-string $migrations */
	public function addMigration(string $migrations): static
	{
		$this->config->addMigrationDir($migrations);

		return $this;
	}

	/** @psalm-param SqlConfig $dirs */
	public function migrationNamespace(string $namespace, string|array $dirs): static
	{
		$this->config->setMigrationNamespace($namespace, $dirs);

		return $this;
	}

	/**
	 * The namespaces `db:migrations` runs when no `--namespace` is given, in
	 * this order: `['default', 'billing']` applies `default` first, then
	 * `billing`. On SQLite and PostgreSQL the whole run is one transaction,
	 * so a failing migration in any namespace leaves the database unchanged.
	 * Without it, the command runs `default` alone.
	 *
	 * @param list<string> $namespaces
	 */
	public function migrationOrder(array $namespaces): static
	{
		$this->config->setMigrationOrder($namespaces);

		return $this;
	}

	/**
	 * Calls the hook with the run's environment and a namespace before the
	 * first pending migration of that namespace, inside the run's
	 * transaction where the driver has one, for example to create the schema
	 * the migrations write to. It is not recorded and runs again whenever
	 * the namespace has pending migrations, so it must do nothing when its
	 * work is done. A plan without `--apply` or `--test-run` does not call it.
	 * An exception it throws fails the run like a failing migration.
	 *
	 * @param Closure(Environment, string): void $hook
	 */
	public function beforeMigrations(Closure $hook): static
	{
		$this->beforeMigrationsHook = $hook;

		return $this;
	}

	/** @psalm-param SqlConfig $sql */
	public function addSql(array|string $sql): static
	{
		$this->config->addSqlDirs($sql);

		return $this;
	}
}
