<?php

declare(strict_types=1);

namespace Celemas\Quma;

/**
 * @api
 *
 * @psalm-import-type SqlConfig from Config
 * @psalm-import-type MigrationDirs from Config
 * @psalm-import-type PlaceholderConfig from Config
 */
class Connection
{
	protected Config $config;

	/** @psalm-param SqlConfig $sql */
	public function __construct(string $dsn, string|array $sql)
	{
		$this->config = new Config($dsn, $sql);
	}

	public function dsn(): string
	{
		return $this->config->dsn;
	}

	/** @return non-empty-string */
	public function driver(): string
	{
		return $this->config->driver;
	}

	public function username(): ?string
	{
		return $this->config->pdo->username;
	}

	public function password(): ?string
	{
		return $this->config->pdo->password;
	}

	/** @return array<array-key, mixed> */
	public function pdoOptions(): array
	{
		return $this->config->pdo->options;
	}

	public function fetchMode(): int
	{
		return $this->config->pdo->fetchMode;
	}

	public function credentials(
		string $username,
		#[\SensitiveParameter]
		?string $password = null,
	): static {
		$this->config->pdo->username = $username;
		$this->config->pdo->password = $password;

		return $this;
	}

	/** @param array<array-key, mixed> $options */
	public function options(array $options): static
	{
		$this->config->pdo->options = $options;

		return $this;
	}

	public function option(int $attribute, mixed $value): static
	{
		$this->config->pdo->options[$attribute] = $value;

		return $this;
	}

	public function fetch(int $fetchMode): static
	{
		$this->config->pdo->fetchMode = $fetchMode;

		return $this;
	}

	/** @psalm-param PlaceholderConfig $placeholders */
	public function placeholders(Delimiters $delimiters, array $placeholders): static
	{
		$this->config->setPlaceholders($delimiters, $placeholders);

		return $this;
	}

	public function placeholderDelimiters(): ?Delimiters
	{
		return $this->config->placeholders?->delimiters();
	}

	/** @return array<string, string> */
	public function placeholderValues(): array
	{
		return $this->config->placeholders?->values() ?? [];
	}

	public function applyPlaceholders(
		string $source,
		string $path,
		bool $isTemplate = false,
	): string {
		return $this->config->placeholders?->compile($source, $path, $isTemplate) ?? $source;
	}

	public function assertNoTemplatePlaceholders(string $source, string $path): void
	{
		$this->config->placeholders?->assertNoTemplatePlaceholders($source, $path);
	}

	public function cache(string $cacheDir): static
	{
		$this->config->setCacheDir($cacheDir);

		return $this;
	}

	public function noCache(): static
	{
		$this->config->clearCacheDir();

		return $this;
	}

	/** @return non-empty-string|null */
	public function cacheDir(): ?string
	{
		return $this->config->cacheDir;
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

	public function migrationsTable(): string
	{
		return $this->config->migrationsTable();
	}

	public function migrationsColumnMigration(): string
	{
		return $this->config->migrationsColumnMigration();
	}

	public function migrationsColumnApplied(): string
	{
		return $this->config->migrationsColumnApplied();
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

	/** @psalm-return MigrationDirs */
	public function migrationDirs(): array
	{
		return $this->config->migrations;
	}

	/** @psalm-param SqlConfig $sql */
	public function addSql(array|string $sql): static
	{
		$this->config->addSqlDirs($sql);

		return $this;
	}

	/** @return list<non-empty-string> */
	public function sql(): array
	{
		return $this->config->sql;
	}
}
