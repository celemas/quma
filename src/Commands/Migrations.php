<?php

declare(strict_types=1);

namespace Celemas\Quma\Commands;

use Celemas\Cli\Command;
use Celemas\Cli\Opts;
use Celemas\Quma\Connection;
use Celemas\Quma\Database;
use Celemas\Quma\Environment;
use Celemas\Quma\MigrationInterface;
use Override;
use RuntimeException;
use Throwable;

final class Migrations extends Command
{
	protected const string STARTED = 'start';
	protected const string ERROR = 'error';
	protected const string WARNING = 'warning';
	protected const string SUCCESS = 'success';

	protected readonly Environment $env;
	protected string $name = 'migrations';
	protected string $group = 'Database';
	protected string $prefix = 'db';
	protected string $description = 'Apply missing database migrations';

	/** @param array<non-empty-string, Connection>|Connection $conn */
	public function __construct(array|Connection $conn, array $options = [])
	{
		if (is_array($conn)) {
			$this->env = new Environment($conn, $options);
		} else {
			$this->env = new Environment(['default' => $conn], $options);
		}
	}

	#[Override]
	public function run(): string|int
	{
		$env = $this->env;
		$opts = new Opts();
		$namespace = $opts->get('--namespace', '');
		$showStacktrace = $opts->has('--stacktrace');
		$apply = $opts->has('--apply');
		$driverSupported = in_array($env->driver, ['sqlite', 'mysql', 'pgsql'], strict: true);
		$tableExists = $driverSupported && $env->checkIfMigrationsTableExists($env->db);

		if (!$apply && $env->driver === 'mysql') {
			return $this->planMigrations($namespace, $tableExists);
		}

		if ($driverSupported && !$tableExists && !$this->supportsTransactions()) {
			$result = $this->createMigrationsTable();

			if ($result !== 0) {
				return $result; // @codeCoverageIgnore
			}

			$tableExists = true;
		}

		return $this->migrate(
			$namespace,
			$showStacktrace,
			$apply,
			$tableExists,
		);
	}

	protected function migrate(
		string $namespace,
		bool $showStacktrace,
		bool $apply,
		bool $tableExists,
	): int {
		$migrations = $this->migrationsForNamespace($namespace);

		if ($migrations === false) {
			return 1;
		}

		return $this->runMigrations(
			$namespace !== '' ? $namespace : 'default',
			$migrations,
			$showStacktrace,
			$apply,
			$tableExists,
		);
	}

	/**
	 * @return list<string>|false
	 */
	protected function migrationsForNamespace(string $namespace): array|false
	{
		$migrationNamespaces = $this->env->getMigrations();

		if ($migrationNamespaces === false) {
			return false;
		}

		if ($namespace) {
			if (!array_key_exists($namespace, $migrationNamespaces)) {
				$this->error("Migration namespace '{$namespace}' does not exist");

				return false;
			}

			$migrations = $migrationNamespaces[$namespace];

			return $this->migrationIdsAreUnique($namespace, $migrations) ? $migrations : false;
		}

		if (!array_key_exists('default', $migrationNamespaces)) {
			$this->error("Migration namespace 'default' does not exist");
			$this->info(
				'If you have defined namespaced migrations, you must either provide a namespace using the '
				. "`--namespace` flag when running this command, or define a namespace named 'default' which "
				. 'will be used when no namespace is provided.',
			);

			return false;
		}

		$migrations = $migrationNamespaces['default'];

		return $this->migrationIdsAreUnique('default', $migrations) ? $migrations : false;
	}

	/** @param list<string> $migrations */
	protected function migrationIdsAreUnique(string $namespace, array $migrations): bool
	{
		/** @var array<string, list<string>> $pathsById */
		$pathsById = [];

		foreach ($migrations as $migration) {
			assert($migration !== '', 'Migration path must be a non-empty string.');

			if (!$this->supportedByDriver($migration)) {
				continue;
			}

			$pathsById[$this->migrationId($namespace, $migration)][] = $migration;
		}

		$foundDuplicate = false;

		foreach ($pathsById as $id => $paths) {
			if (count($paths) < 2) {
				continue;
			}

			$foundDuplicate = true;
			$this->error("Duplicate migration id '{$id}' in namespace '{$namespace}'");

			foreach ($paths as $path) {
				echo "  - {$path}\n";
			}
		}

		return !$foundDuplicate;
	}

	protected function runMigrations(
		string $namespace,
		array $migrations,
		bool $showStacktrace,
		bool $apply,
		bool $tableExists,
	): int {
		$db = $this->env->db;
		$conn = $this->env->conn;

		$this->begin($db);

		if (!$tableExists) {
			$result = $this->createMigrationsTable();

			// @codeCoverageIgnoreStart
			if ($result !== 0) {
				if ($this->supportsTransactions()) {
					$db->rollback();
				}

				return $result;
			} // @codeCoverageIgnoreEnd
		}

		$appliedMigrations = $this->getAppliedMigrations($db);
		$result = self::STARTED;
		$numApplied = 0;

		foreach ($migrations as $migration) {
			assert(is_string($migration) && $migration !== '', 'Migration path must be a non-empty string.');

			$migrationId = $this->migrationId($namespace, $migration);

			if (in_array($migrationId, $appliedMigrations, strict: true)) {
				continue;
			}

			if (!$this->supportedByDriver($migration)) {
				continue;
			}

			$script = file_get_contents($migration);

			if ($script === false) {
				$this->showMessage($migration, new RuntimeException('Could not read migration file'));
				$result = self::ERROR;

				break;
			}

			if (trim($script) === '') {
				$this->showEmptyMessage($migration);
				$result = self::WARNING;

				continue;
			}

			$result = match (pathinfo($migration, PATHINFO_EXTENSION)) {
				'sql' => $this->migrateSQL($namespace, $migration, $script, $showStacktrace),
				'tpql' => $this->migrateTPQL($db, $conn, $namespace, $migration, $showStacktrace),
				'php' => $this->migratePHP($db, $namespace, $migration, $showStacktrace),
			};

			if ($result === self::ERROR) {
				break;
			}

			if ($result === self::SUCCESS) {
				$numApplied++;
			}
		}

		return $this->finish($db, $result, $apply, $numApplied);
	}

	protected function begin(Database $db): void
	{
		if ($this->supportsTransactions()) {
			$db->begin();
		}
	}

	protected function createMigrationsTable(): int
	{
		$createMigrationTableCmd = new CreateMigrationsTable($this->env->conn, $this->env->options);
		$result = $createMigrationTableCmd->run();

		if ($result === 0) {
			return 0;
		}

		// Would require simulating a failing CreateMigrationsTable command
		// without a test seam or altering the public API.
		// @codeCoverageIgnoreStart
		$this->error('Migration table could not be created.');

		return is_int($result) ? $result : 1;

		// @codeCoverageIgnoreEnd
	}

	protected function finish(
		Database $db,
		string $result,
		bool $apply,
		int $numApplied,
	): int {
		$plural = $numApplied > 1 ? 's' : '';

		if ($this->supportsTransactions()) {
			if ($result === self::ERROR) {
				$db->rollback();
				echo "\nDue to errors no migrations applied\n";

				return 1;
			}

			if ($numApplied === 0) {
				$db->rollback();
				echo "\nNo migrations applied\n";

				return 0;
			}

			if ($apply) {
				$db->commit();
				echo "\n{$numApplied} migration{$plural} successfully applied\n";

				return 0;
			}
			echo "\n\033[1;31mNotice\033[0m: Test run only\033[0m";
			echo "\nWould apply {$numApplied} migration{$plural}. ";
			echo "Use the switch --apply to make it happen\n";
			$db->rollback();

			return 0;
		}

		if ($result === self::ERROR) {
			echo "\n{$numApplied} migration{$plural} applied until the error occured\n";

			return 1;
		}

		if ($numApplied > 0) {
			echo "\n{$numApplied} migration{$plural} successfully applied\n";

			return 0;
		}

		echo "\nNo migrations applied\n";

		return 0;
	}

	protected function supportsTransactions(): bool
	{
		switch ($this->env->driver) {
			case 'sqlite':
				return true;
			case 'pgsql':
				return true;
			case 'mysql':
				return false;
		}

		// An unsupported driver would have to be installed
		// to be able to test meaningfully
		// @codeCoverageIgnoreStart
		throw new RuntimeException('Database driver not supported');

		// @codeCoverageIgnoreEnd
	}

	/**
	 * @return list<string>
	 */
	protected function getAppliedMigrations(Database $db): array
	{
		$table = $this->env->table;
		$column = $this->env->columnMigration;
		$migrations = $db->execute("SELECT {$column} AS migration FROM {$table};")->all();

		return array_map(
			static fn(array $mig): string => (string) $mig['migration'],
			$migrations,
		);
	}

	protected function planMigrations(string $namespace, bool $tableExists): int
	{
		$migrations = $this->migrationsForNamespace($namespace);

		if ($migrations === false) {
			return 1; // @codeCoverageIgnore
		}

		$namespace = $namespace !== '' ? $namespace : 'default';
		$appliedMigrations = $tableExists ? $this->getAppliedMigrations($this->env->db) : [];
		$pendingMigrations = $this->pendingMigrations($namespace, $migrations, $appliedMigrations);
		$numPending = count($pendingMigrations);
		$plural = $numPending > 1 ? 's' : '';

		echo "\n\033[1;31mNotice\033[0m: Test run only\033[0m\n";

		if (!$tableExists) {
			echo "Would create migrations table '{$this->env->table}'\n";
		}

		if ($numPending === 0) {
			echo "\nNo migrations applied\n"; // @codeCoverageIgnore
		} else {
			echo "Would apply {$numPending} migration{$plural}:\n";

			foreach ($pendingMigrations as $migration) {
				echo '  - ' . basename($migration) . "\n";
			}
		}

		echo "\nMySQL migrations are not executed during dry runs because Quma cannot safely ";
		echo "roll back the full migration batch on this driver. Use --apply to run them.\n";

		return 0;
	}

	/**
	 * @param list<string> $migrations
	 * @param list<string> $appliedMigrations
	 *
	 * @return list<string>
	 */
	protected function pendingMigrations(
		string $namespace,
		array $migrations,
		array $appliedMigrations,
	): array {
		$pending = [];

		foreach ($migrations as $migration) {
			assert($migration !== '', 'Migration path must be a non-empty string.');

			if (in_array($this->migrationId($namespace, $migration), $appliedMigrations, strict: true)) {
				continue;
			}

			if (!$this->supportedByDriver($migration)) {
				continue;
			}

			$pending[] = $migration;
		}

		return $pending;
	}

	protected function migrationId(string $namespace, string $migration): string
	{
		$name = basename($migration);

		if ($namespace === 'default') {
			return $name;
		}

		return $namespace . ':' . $name;
	}

	/**
	 * Returns if the given migration is driver specific.
	 */
	protected function supportedByDriver(string $migration): bool
	{
		// First checks if there are brackets in the filename.
		if (preg_match('/\[[a-z]{3,8}\]/', $migration)) {
			// We have found a driver specific migration.
			// Check if it matches the current driver.
			if (preg_match('/\[' . $this->env->driver . '\]/', $migration)) {
				return true;
			}

			return false;
		}

		// This is no driver specific migration
		return true;
	}

	protected function migrateSQL(
		string $namespace,
		string $migration,
		string $script,
		bool $showStacktrace,
	): string {
		try {
			$script = $this->env->conn->applyPlaceholders($script, $migration);

			return $this->migrateCompiledSQL($namespace, $migration, $script);
		} catch (Throwable $e) {
			$this->showMessage($migration, $e, $showStacktrace);

			return self::ERROR;
		}
	}

	protected function migrateCompiledSQL(
		string $namespace,
		string $migration,
		string $script,
	): string {
		if (trim($script) === '') {
			$this->showEmptyMessage($migration);

			return self::WARNING;
		}

		$db = $this->env->db;
		$db->execute($script)->run();
		$this->logMigration($db, $namespace, $migration);
		$this->showMessage($migration);

		return self::SUCCESS;
	}

	protected function migrateTPQL(
		Database $db,
		Connection $conn,
		string $namespace,
		string $migration,
		bool $showStacktrace,
	): string {
		try {
			$context = [
				'driver' => $db->getPdoDriver(),
				'db' => $db,
				'conn' => $conn,
			];

			$executeTemplate = static function (
				string $templatePath,
				array $context,
			): void {
				extract($context, EXTR_SKIP);

				/** @psalm-suppress UnresolvableInclude */
				require $templatePath;
			};

			if (!is_file($migration)) {
				throw new RuntimeException('Could not read migration file');
			}

			$template = file_get_contents($migration);

			if ($template === false) {
				throw new RuntimeException('Could not read migration file'); // @codeCoverageIgnore
			}

			$template = $conn->applyPlaceholders($template, $migration, true);
			$templatePath = $this->writeTemplateCache($template);

			ob_start();
			$script = '';

			try {
				$executeTemplate($templatePath, $context);
				$script = ob_get_contents();
			} finally {
				ob_end_clean();

				if (is_file($templatePath)) {
					unlink($templatePath);
				}
			}

			if (!is_string($script) || trim($script) === '') {
				$this->showEmptyMessage($migration);

				return self::WARNING;
			}

			$conn->assertNoTemplatePlaceholders($script, $migration);

			return $this->migrateCompiledSQL($namespace, $migration, $script);
		} catch (Throwable $e) {
			$this->showMessage($migration, $e, $showStacktrace);

			return self::ERROR;
		}
	}

	protected function writeTemplateCache(string $template): string
	{
		$templatePath = tempnam(sys_get_temp_dir(), 'quma-mig-');

		if ($templatePath === false) {
			throw new RuntimeException('Could not create migration template cache file'); // @codeCoverageIgnore
		}

		if (file_put_contents($templatePath, $template) === false) {
			// @codeCoverageIgnoreStart
			if (is_file($templatePath)) {
				unlink($templatePath);
			}

			throw new RuntimeException('Could not write migration template cache file');
		} // @codeCoverageIgnoreEnd

		return $templatePath;
	}

	protected function migratePHP(
		Database $db,
		string $namespace,
		string $migration,
		bool $showStacktrace,
	): string {
		try {
			$migObj = $this->loadPhpMigration($migration);
			$migObj->run($this->env);
			$this->logMigration($db, $namespace, $migration);
			$this->showMessage($migration);

			return self::SUCCESS;
		} catch (Throwable $e) {
			$this->showMessage($migration, $e, $showStacktrace);

			return self::ERROR;
		}
	}

	protected function loadPhpMigration(string $migration): MigrationInterface
	{
		if (!is_file($migration)) {
			throw new RuntimeException('Could not read migration file');
		}

		$migrationObject = require $migration;

		if (!$migrationObject instanceof MigrationInterface) {
			throw new RuntimeException('Invalid migration file. Expected MigrationInterface instance');
		}

		return $migrationObject;
	}

	protected function logMigration(Database $db, string $namespace, string $migration): void
	{
		$table = $this->env->table;
		$column = $this->env->columnMigration;

		$db->execute(
			"INSERT INTO {$table} ({$column}) VALUES (:migration)",
			['migration' => $this->migrationId($namespace, $migration)],
		)->run();
	}

	protected function showEmptyMessage(string $migration): void
	{
		echo
			"\033[33mWarning\033[0m: Migration '\033[1;33m"
				. basename($migration)
				. "'\033[0m is empty. Skipped\n"
		;
	}

	protected function showMessage(
		string $migration,
		?Throwable $e = null,
		bool $showStacktrace = false,
	): void {
		if ($e) {
			echo
				"\033[1;31mError\033[0m: while working on migration '\033[1;33m"
					. basename($migration)
					. "\033[0m'\n"
			;
			echo $e->getMessage() . "\n";

			if ($showStacktrace) {
				echo $e->getTraceAsString() . "\n";
			}

			return;
		}

		echo
			"\033[1;32mSuccess\033[0m: Migration '\033[1;33m"
				. basename($migration)
				. "\033[0m' successfully applied\n"
		;
	}
}
