<?php

declare(strict_types=1);

namespace Celemas\Quma\Commands;

use Celemas\Cli\Command;
use Celemas\Cli\Opts;
use Celemas\Quma\Connection;
use Celemas\Quma\Contract;
use Celemas\Quma\Environment;
use Celemas\Quma\Migrations\DriverPolicy;
use Celemas\Quma\Migrations\Executor;
use Celemas\Quma\Migrations\Log;
use Celemas\Quma\Migrations\MetadataTable;
use Celemas\Quma\Migrations\PhpLoader;
use Celemas\Quma\Migrations\Plan;
use Celemas\Quma\Migrations\Planner;
use Celemas\Quma\Migrations\Runner;
use Celemas\Quma\Migrations\RunOptions;
use Celemas\Quma\Migrations\TestRunConfirmation;
use Override;

final class Migrations extends Command
{
	protected readonly Environment $env;
	protected readonly ?Contract\MigrationFactory $migrationFactory;
	protected string $name = 'migrations';
	protected string $group = 'Database';
	protected string $prefix = 'db';
	protected string $description = 'Apply missing database migrations';

	/** @param array<non-empty-string, Connection>|Connection $conn */
	public function __construct(
		array|Connection $conn,
		array $options = [],
		?Contract\MigrationFactory $migrationFactory = null,
	) {
		if (is_array($conn)) {
			$this->env = new Environment($conn, $options);
		} else {
			$this->env = new Environment(['default' => $conn], $options);
		}

		$this->migrationFactory = $migrationFactory;
	}

	#[Override]
	public function run(): string|int
	{
		$env = $this->env;
		$opts = new Opts();
		$namespace = $opts->get('--namespace', '');
		$showStacktrace = $opts->has('--stacktrace');
		$apply = $opts->has('--apply');
		$testRun = $opts->has('--test-run');
		$yes = $opts->has('--yes');
		$driverSupported = $this->driverPolicy()->isKnown();

		if ($apply && $testRun) {
			echo "\033[1;31mError\033[0m: Options --apply and --test-run cannot be used together.\n";

			return 1;
		}

		if ($testRun && (!$driverSupported || !$this->supportsTransactions())) {
			echo
				"\033[1;31mError\033[0m: Test runs are only supported for transactional drivers: sqlite and pgsql.\n"
			;
			echo
				"MySQL migrations are plan-only without --apply because DDL statements can cause implicit commits.\n"
			;

			return 1;
		}

		$migrations = $this->migrationsForNamespace($namespace);

		if ($migrations === false) {
			return 1;
		}

		$migrationNamespace = $namespace !== '' ? $namespace : 'default';
		$tableExists = $driverSupported && $env->checkIfMigrationsTableExists($env->db);

		if (!$apply && !$testRun) {
			return $this->plan()->show($migrationNamespace, $migrations, $tableExists);
		}

		if (
			!$apply && !$this->confirmTestRunForPending($migrationNamespace, $migrations, $tableExists, $yes)
		) {
			return 1;
		}

		if ($driverSupported && !$tableExists && !$this->supportsTransactions()) {
			$result = $this->createMigrationsTable();

			if ($result !== 0) {
				// Requires simulating failing metadata table creation.
				return $result; // @codeCoverageIgnore
			}

			$tableExists = true;
		}

		return $this->migrate(
			$migrationNamespace,
			$migrations,
			$showStacktrace,
			$apply,
			$tableExists,
		);
	}

	/** @param list<string> $migrations */
	protected function migrate(
		string $namespace,
		array $migrations,
		bool $showStacktrace,
		bool $apply,
		bool $tableExists,
	): int {
		return $this->runner()->run(
			$namespace,
			$migrations,
			new RunOptions(
				$showStacktrace,
				$apply,
				$tableExists,
				$this->createMigrationsTable(...),
			),
		);
	}

	/**
	 * @param list<string> $migrations
	 */
	protected function confirmTestRunForPending(
		string $namespace,
		array $migrations,
		bool $tableExists,
		bool $yes,
	): bool {
		$appliedMigrations = $tableExists ? $this->log()->applied($this->env->db) : [];
		$pendingMigrations = $this->planner()->pendingMigrations(
			$namespace,
			$migrations,
			$appliedMigrations,
		);

		if (count($pendingMigrations) === 0) {
			return true;
		}

		return new TestRunConfirmation()->confirm($yes);
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
		$duplicates = $this->planner()->duplicateMigrationIds($namespace, $migrations);

		foreach ($duplicates as $id => $paths) {
			$this->error("Duplicate migration id '{$id}' in namespace '{$namespace}'");

			foreach ($paths as $path) {
				echo "  - {$path}\n";
			}
		}

		return count($duplicates) === 0;
	}

	protected function createMigrationsTable(): int
	{
		$result = new MetadataTable($this->env)->create($this->env->db);

		if ($result === 0) {
			return 0;
		}

		// Would require simulating failing metadata table creation.
		// @codeCoverageIgnoreStart
		$this->error('Migration table could not be created.');

		return $result;

		// @codeCoverageIgnoreEnd
	}

	protected function supportsTransactions(): bool
	{
		return $this->driverPolicy()->supportsTransactions();
	}

	private function driverPolicy(): DriverPolicy
	{
		return new DriverPolicy($this->env->driver);
	}

	private function planner(): Planner
	{
		return new Planner($this->driverPolicy());
	}

	private function phpLoader(): PhpLoader
	{
		return new PhpLoader($this->env, $this->migrationFactory);
	}

	private function log(): Log
	{
		return new Log($this->env, $this->planner());
	}

	private function plan(): Plan
	{
		return new Plan($this->env, $this->planner(), $this->log());
	}

	private function executor(): Executor
	{
		return new Executor($this->env, $this->log(), $this->phpLoader());
	}

	private function runner(): Runner
	{
		return new Runner(
			$this->env,
			$this->driverPolicy(),
			$this->planner(),
			$this->log(),
			$this->executor(),
		);
	}
}
