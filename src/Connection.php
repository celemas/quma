<?php

declare(strict_types=1);

namespace Celemas\Quma;

/**
 * @api
 *
 * @psalm-import-type SqlConfig from Config
 * @psalm-import-type PlaceholderConfig from Config
 */
class Connection
{
	public readonly Config $config;

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

	public function migrationNamespace(string $namespace, string|array $dirs): static
	{
		$this->config->setMigrationNamespace($namespace, $dirs);

		return $this;
	}

	/** @psalm-param SqlConfig $sql */
	public function addSql(array|string $sql): static
	{
		$this->config->addSqlDirs($sql);

		return $this;
	}
}
