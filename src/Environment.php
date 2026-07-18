<?php

declare(strict_types=1);

namespace Celema\Quma;

use Celema\Console\Args;
use PDO;
use RuntimeException;

/**
 * @api
 */
class Environment
{
	public readonly Connection $conn;
	public readonly string $driver;
	public readonly bool $showStacktrace;
	public readonly string $table;
	public readonly string $columnMigration;
	public readonly string $columnApplied;
	public readonly Database $db;

	/** @param array<non-empty-string, Connection> $connections */
	public function __construct(
		array $connections,
		public readonly array $options,
	) {
		$argv = $_SERVER['argv'] ?? [];
		$args = new Args($argv);

		$key = $args->opt('--conn', 'default');

		if (!array_key_exists($key, $connections)) {
			throw new RuntimeException("Connection '{$key}' does not exist");
		}

		$this->conn = $connections[$key];
		$this->showStacktrace = $args->has('--stacktrace');
		$this->db = new Database($this->conn);
		$this->driver = $this->conn->config->driver;
		$this->table = $this->conn->config->migrationsTable;
		$this->columnMigration = $this->conn->config->migrationsColumnMigration;
		$this->columnApplied = $this->conn->config->migrationsColumnApplied;
	}

	/**
	 * @return array<string, list<string>>|false
	 */
	public function getMigrations(): array|false
	{
		$migrations = [];
		$migrationDirs = $this->conn->config->migrations;

		if (count($migrationDirs) === 0) {
			echo "\033[1;31mNotice\033[0m: No migration directories defined in configuration\033[0m\n";

			return false;
		}

		// Check if migrations is a flat list or namespaced
		if (array_is_list($migrationDirs)) {
			// Flat list: wrap in 'default' namespace
			$dirs = $this->normalizeMigrationDirs($migrationDirs);

			if (count($dirs) > 0) {
				$migrations['default'] = $this->collectMigrations($dirs);
			}
		} else {
			// Namespaced: process each namespace
			array_walk(
				$migrationDirs,
				function (mixed $dirs, int|string $namespace) use (&$migrations): void {
					if (!is_string($namespace)) {
						return; // @codeCoverageIgnore
					}

					if (is_string($dirs)) {
						$resolvedDirs = $this->normalizeMigrationDirs([$dirs]);
					} elseif (is_array($dirs)) {
						$resolvedDirs = $this->normalizeMigrationDirs($dirs);
					} else {
						return; // @codeCoverageIgnore
					}

					if (count($resolvedDirs) === 0) {
						return;
					}

					$migrations[$namespace] = $this->collectMigrations($resolvedDirs);
				},
			);
		}

		return $migrations;
	}

	/**
	 * @param array<array-key, mixed> $dirs
	 *
	 * @return list<non-empty-string>
	 */
	protected function normalizeMigrationDirs(array $dirs): array
	{
		$normalized = [];

		array_walk(
			$dirs,
			static function (mixed $path) use (&$normalized): void {
				if (is_string($path) && $path !== '') {
					$normalized[] = $path;
				}
			},
		);

		return $normalized;
	}

	/**
	 * @param list<non-empty-string> $migrationDirs
	 *
	 * @return list<string>
	 */
	protected function collectMigrations(array $migrationDirs): array
	{
		$migrations = [];

		foreach ($migrationDirs as $path) {
			$phpFiles = glob("{$path}/*.php");
			$sqlFiles = glob("{$path}/*.sql");
			$tpqlFiles = glob("{$path}/*.tpql");

			$migrations = array_merge(
				$migrations,
				is_array($phpFiles) ? array_filter($phpFiles, 'is_file') : [],
				is_array($sqlFiles) ? array_filter($sqlFiles, 'is_file') : [],
				is_array($tpqlFiles) ? array_filter($tpqlFiles, 'is_file') : [],
			);
		}

		// Sort by file name instead of full path
		uasort($migrations, static function ($a, $b) {
			$a = is_string($a) ? $a : '';
			$b = is_string($b) ? $b : '';

			return basename($a) < basename($b) ? -1 : 1;
		});

		return array_values($migrations);
	}

	public function checkIfMigrationsTableExists(Database $db): bool
	{
		$driver = $db->getPdoDriver();
		$table = $this->table;

		if ($driver === 'pgsql' && str_contains($table, '.')) {
			[$schema, $table] = explode('.', $table);
		} else {
			$schema = 'public';
		}

		$query = match ($driver) {
			'sqlite' => "
                SELECT count(*) AS available
                FROM sqlite_master
                WHERE type='table'
                AND name='{$table}';",
			'mysql' => "
                SELECT count(*) AS available
                FROM information_schema.tables
                WHERE table_schema = DATABASE()
                AND table_name='{$table}';",
			'pgsql' => "
                SELECT count(*) AS available
                FROM pg_tables
                WHERE schemaname = '{$schema}'
                AND tablename = '{$table}';",
		};

		if ($query && ($db->execute($query)->one(fetchMode: PDO::FETCH_ASSOC)['available'] ?? 0) === 1) {
			return true;
		}

		return false;
	}

	public function getMigrationsTableDDL(): string|false
	{
		if ($this->driver === 'pgsql' && str_contains($this->table, '.')) {
			[$schema, $table] = explode('.', $this->table);
		} else {
			$schema = 'public';
			$table = $this->table;
		}
		$columnMigration = $this->columnMigration;
		$columnApplied = $this->columnApplied;

		switch ($this->driver) {
			case 'sqlite':
				return "CREATE TABLE {$table} (
    {$columnMigration} text NOT NULL,
    {$columnApplied} text DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY ({$columnMigration}),
    CHECK(typeof(\"{$columnMigration}\") = \"text\" AND length(\"{$columnMigration}\") <= 256),
    CHECK(typeof(\"{$columnApplied}\") = \"text\" AND length(\"{$columnApplied}\") = 19)
);";

			case 'pgsql':
				return "CREATE TABLE {$schema}.{$table} (
    {$columnMigration} text NOT NULL CHECK (char_length({$columnMigration}) <= 256),
    {$columnApplied} timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT pk_{$table} PRIMARY KEY ({$columnMigration})
);";

			case 'mysql':
				return "CREATE TABLE {$table} (
    {$columnMigration} varchar(256) NOT NULL,
    {$columnApplied} timestamp DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY ({$columnMigration})
);";

			default:
				// Cannot be reliably tested.
				// Would require an unsupported driver to be installed.
				// @codeCoverageIgnoreStart
				return false;

			// @codeCoverageIgnoreEnd
		}
	}
}
