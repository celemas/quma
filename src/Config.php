<?php

declare(strict_types=1);

namespace Celemas\Quma;

use PDO;
use RuntimeException;
use ValueError;

/**
 * @api
 *
 * @psalm-type SqlDirs = list<non-empty-string>
 * @psalm-type SqlAssoc = array<non-empty-string, non-empty-string|list<non-empty-string>>
 * @psalm-type SqlMixed = list<non-empty-string|SqlAssoc>
 * @psalm-type SqlConfig = non-empty-string|SqlAssoc|SqlMixed
 * @psalm-type MigrationDirsFlat = list<non-empty-string>
 * @psalm-type MigrationDirsNamespaced = array<non-empty-string, non-empty-string|list<non-empty-string>>
 * @psalm-type MigrationDirs = MigrationDirsFlat|MigrationDirsNamespaced
 * @psalm-type PlaceholderMap = array<non-empty-string, string>
 * @psalm-type PlaceholderConfig = array<non-empty-string, PlaceholderMap>
 */
final class Config
{
	/** @var non-empty-string */
	public readonly string $driver;

	/** @psalm-var SqlDirs */
	public private(set) array $sql;

	/** @psalm-var MigrationDirs */
	public private(set) array $migrations = [];

	public private(set) PdoConfig $pdo;
	public private(set) ?Placeholders $placeholders = null;

	/** @var non-empty-string|null */
	public private(set) ?string $cacheDir = null;

	public private(set) string $migrationsTable = 'migrations';
	public private(set) string $migrationsColumnMigration = 'migration';
	public private(set) string $migrationsColumnApplied = 'applied';

	/** @psalm-param SqlConfig $sql */
	public function __construct(
		public readonly string $dsn,
		string|array $sql,
	) {
		$this->driver = $this->readDriver($this->dsn);
		$this->sql = $this->readFlatDirs($sql);
		$this->pdo = new PdoConfig();
	}

	public function setCredentials(
		string $username,
		#[\SensitiveParameter]
		?string $password = null,
	): void {
		$this->pdo = $this->pdo->credentials($username, $password);
	}

	/** @param array<array-key, mixed> $options */
	public function setPdoOptions(array $options): void
	{
		$this->pdo = $this->pdo->options($options);
	}

	public function setPdoOption(int $attribute, mixed $value): void
	{
		$this->pdo = $this->pdo->option($attribute, $value);
	}

	public function setFetchMode(int $fetchMode): void
	{
		$this->pdo = $this->pdo->fetch($fetchMode);
	}

	/** @psalm-param PlaceholderConfig $placeholders */
	public function setPlaceholders(Delimiters $delimiters, array $placeholders): void
	{
		$this->placeholders = new Placeholders($this->driver, $placeholders, $delimiters);
	}

	public function setCacheDir(string $cacheDir): void
	{
		if (!file_exists($cacheDir)) {
			throw new ValueError('Cache directory does not exist: ' . $cacheDir);
		}

		if (!is_dir($cacheDir)) {
			throw new ValueError('Cache path is not a directory: ' . $cacheDir);
		}

		if (!is_writable($cacheDir)) {
			throw new ValueError('Cache directory is not writable: ' . $cacheDir); // @codeCoverageIgnore
		}

		$path = realpath($cacheDir);

		if ($path === false || $path === '') {
			throw new ValueError('Cache directory does not exist: ' . $cacheDir); // @codeCoverageIgnore
		}

		$this->cacheDir = $path;
	}

	public function clearCacheDir(): void
	{
		$this->cacheDir = null;
	}

	/** @psalm-param SqlConfig $sql */
	public function addSqlDirs(array|string $sql): void
	{
		$dirs = $this->readFlatDirs($sql);
		$this->sql = array_merge($dirs, $this->sql);
	}

	/** @psalm-param SqlConfig $migrations */
	public function setMigrations(array|string $migrations): void
	{
		$this->migrations = $this->readMigrationDirs($migrations);
	}

	/** @param non-empty-string $migrations */
	public function addMigrationDir(string $migrations): void
	{
		if (!array_is_list($this->migrations)) {
			throw new ValueError(
				'Cannot add a flat migration directory when migrations are namespaced. Use migrationNamespace().',
			);
		}

		$dirs = $this->readFlatDirs($migrations);
		$this->migrations = array_merge($dirs, $this->migrations);
	}

	public function setMigrationNamespace(string $namespace, string|array $dirs): void
	{
		if ($namespace === '') {
			throw new ValueError('Migration namespace must not be empty.');
		}

		if (array_is_list($this->migrations) && count($this->migrations) > 0) {
			throw new ValueError(
				'Cannot add a namespaced migration directory when migrations are configured as a flat list.',
			);
		}

		/** @psalm-var MigrationDirsNamespaced $migrations */
		$migrations = array_is_list($this->migrations) ? [] : $this->migrations;
		$migrations[$namespace] = is_string($dirs)
			? $this->preparePath($dirs)
			: $this->readDirsEntry($dirs);
		$this->migrations = $migrations;
	}

	public function setMigrationsTable(string $table): void
	{
		$this->migrationsTable = $this->getMigrationsTableName($table);
	}

	public function setMigrationsColumnMigration(string $column): void
	{
		$this->migrationsColumnMigration = $this->getColumnName($column);
	}

	public function setMigrationsColumnApplied(string $column): void
	{
		$this->migrationsColumnApplied = $this->getColumnName($column);
	}

	/** @return non-empty-string */
	private function preparePath(string $path): string
	{
		$result = realpath($path);

		if ($result !== false && $result !== '') {
			return $result;
		}

		throw new ValueError("Path does not exist: {$path}");
	}

	/** @return non-empty-string */
	private function readDriver(string $dsn): string
	{
		$driver = explode(':', $dsn)[0];

		if (in_array($driver, PDO::getAvailableDrivers(), strict: true)) {
			assert($driver !== '', 'PDO driver name must not be empty.');

			return $driver;
		}

		throw new RuntimeException('PDO driver not supported: ' . $driver);
	}

	/**
	 * Reads directories from configuration into a flat list.
	 *
	 * @psalm-param SqlConfig $config
	 *
	 * @return list<non-empty-string>
	 */
	private function readFlatDirs(string|array $config, bool $preserveOrder = false): array
	{
		if (is_string($config)) {
			return [$this->preparePath($config)];
		}

		if (count($config) === 0) {
			return [];
		}

		if (Util::isAssoc($config)) {
			return $this->readAssocDirs($config);
		}

		$dirs = [];

		foreach ($config as $entry) {
			if (is_string($entry)) {
				if ($preserveOrder) {
					$dirs[] = $this->preparePath($entry);
				} else {
					array_unshift($dirs, $this->preparePath($entry));
				}

				continue;
			}

			if (array_is_list($entry)) {
				foreach ($entry as $path) {
					if (!is_string($path)) {
						continue;
					}

					if ($preserveOrder) {
						$dirs[] = $this->preparePath($path);
					} else {
						array_unshift($dirs, $this->preparePath($path));
					}
				}

				continue;
			}

			if ($preserveOrder) {
				$dirs = array_merge($dirs, $this->readAssocDirs($entry));
			} else {
				$dirs = array_merge($this->readAssocDirs($entry), $dirs);
			}
		}

		return $dirs;
	}

	/**
	 * Reads directories from an associative array config.
	 *
	 * @param array<array-key, mixed> $entry
	 *
	 * @return list<non-empty-string>
	 */
	private function readAssocDirs(array $entry): array
	{
		$hasDriver = array_key_exists($this->driver, $entry);
		$hasAll = array_key_exists('all', $entry);
		$dirs = [];

		if ($hasDriver) {
			$dirs = array_merge($dirs, $this->readDirsEntry($entry[$this->driver]));
		}

		if ($hasAll) {
			$dirs = array_merge($dirs, $this->readDirsEntry($entry['all']));
		}

		return $dirs;
	}

	/** @return list<non-empty-string> */
	private function readDirsEntry(mixed $entry): array
	{
		if (is_string($entry)) {
			return [$this->preparePath($entry)];
		}

		if (!is_array($entry)) {
			return [];
		}

		$dirs = [];

		array_walk(
			$entry,
			function (mixed $value) use (&$dirs): void {
				if (is_string($value)) {
					$dirs[] = $this->preparePath($value);

					return;
				}

				if (!is_array($value)) {
					return;
				}

				if (Util::isAssoc($value)) {
					$dirs = array_merge($dirs, $this->readAssocDirs($value));

					return;
				}

				array_walk(
					$value,
					function (mixed $path) use (&$dirs): void {
						if (is_string($path)) {
							$dirs[] = $this->preparePath($path);
						}
					},
				);
			},
		);

		return $dirs;
	}

	/**
	 * Reads migration directories from configuration.
	 *
	 * Migrations can be configured as:
	 * - A flat list of directories
	 * - A namespaced structure with string keys mapping to directories
	 *
	 * @psalm-param SqlConfig $config
	 *
	 * @psalm-return MigrationDirs
	 */
	private function readMigrationDirs(string|array $config): array
	{
		if (is_string($config)) {
			return [$this->preparePath($config)];
		}

		if (count($config) === 0) {
			return [];
		}

		if (Util::isAssoc($config) && !$this->isDriverConfig($config)) {
			return $this->readNamespacedDirs($config);
		}

		return $this->readFlatDirs($config);
	}

	/** @param array<array-key, mixed> $config */
	private function isDriverConfig(array $config): bool
	{
		return array_key_exists($this->driver, $config) || array_key_exists('all', $config);
	}

	/**
	 * Reads namespaced migration directories.
	 *
	 * @param array<array-key, mixed> $config
	 *
	 * @psalm-return MigrationDirsNamespaced
	 */
	private function readNamespacedDirs(array $config): array
	{
		$result = [];

		array_walk(
			$config,
			function (mixed $dirs, int|string $namespace) use (&$result): void {
				if (!is_string($namespace) || $namespace === '') {
					return;
				}

				if (is_string($dirs)) {
					$result[$namespace] = $this->preparePath($dirs);

					return;
				}

				if (!is_array($dirs)) {
					return;
				}

				$result[$namespace] = $this->readDirsEntry($dirs);
			},
		);

		return $result;
	}

	private function getMigrationsTableName(string $table): string
	{
		if ($this->driver === 'pgsql') {
			if (preg_match('/^([a-zA-Z0-9_]+\.)?[a-zA-Z0-9_]+$/', $table)) {
				return $table;
			}
		} elseif (preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
			return $table;
		}

		throw new ValueError('Invalid migrations table name: ' . $table);
	}

	private function getColumnName(string $column): string
	{
		if (preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
			return $column;
		}

		throw new ValueError('Invalid migrations table column name: ' . $column);
	}
}
