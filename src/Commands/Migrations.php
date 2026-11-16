<?php

declare(strict_types=1);

namespace Celema\Quma\Commands;

use Celema\Console\Command;
use Celema\Console\Exception\InvalidUsage;
use Celema\Console\Io;
use Celema\Console\Opt;
use Celema\Quma\Connection;
use Celema\Quma\Contract;
use Celema\Quma\Environment;
use Celema\Quma\Migrations\DriverPolicy;
use Celema\Quma\Migrations\Executor;
use Celema\Quma\Migrations\Log;
use Celema\Quma\Migrations\MetadataTable;
use Celema\Quma\Migrations\PhpLoader;
use Celema\Quma\Migrations\Plan;
use Celema\Quma\Migrations\Planner;
use Celema\Quma\Migrations\Runner;
use Celema\Quma\Migrations\RunOptions;
use Celema\Quma\Migrations\TestRunConfirmation;

#[Command('db:migrations', 'Apply missing database migrations', group: 'Database')]
final class Migrations
{
	/** @psalm-suppress PropertyNotSetInConstructor Assigned first thing in __invoke() */
	private Environment $env;

	/** @psalm-suppress PropertyNotSetInConstructor Assigned first thing in __invoke() */
	private Io $io;

	/**
	 * @param array<non-empty-string, Connection>|Connection $connections
	 * @param array<array-key, mixed> $options
	 */
	public function __construct(
		private readonly array|Connection $connections,
		private readonly array $options = [],
		private readonly ?Contract\MigrationFactory $migrationFactory = null,
	) {}

	// The parameters are the command line's options.
	// @mago-expect lint:excessive-parameter-list
	public function __invoke(
		Io $io,
		#[Opt('Apply the pending migrations instead of only planning them')]
		bool $apply = false,
		#[Opt('Run pending migrations inside a transaction and roll back (sqlite/pgsql only)')]
		bool $testRun = false,
		#[Opt('Migration namespace to run', value: 'name')]
		string $namespace = '',
		#[Opt('Connection to use', value: 'name')]
		string $conn = 'default',
		#[Opt('Show stack traces for failing migrations')]
		bool $stacktrace = false,
		#[Opt('Skip the test-run confirmation prompt')]
		bool $yes = false,
	): int {
		if ($apply && $testRun) {
			throw new InvalidUsage('Options --apply and --test-run cannot be used together');
		}

		$this->io = $io;
		$this->env = new Environment($this->connections, $this->options, $conn, $stacktrace);
		$env = $this->env;
		$driverSupported = $this->driverPolicy()->isKnown();

		if ($testRun && (!$driverSupported || !$this->supportsTransactions())) {
			$io->error('Error: Test runs are only supported for transactional drivers: sqlite and pgsql.');
			$io->stderr()->line(
				'MySQL migrations are plan-only without --apply because DDL statements can cause implicit commits.',
			);

			return 1;
		}

		$namespaces = $namespace !== ''
			? $this->namespaces([$namespace], required: true)
			: $this->namespaces($this->env->conn->config->migrationOrder);

		if ($namespaces === false) {
			return 1;
		}

		$tableExists = $driverSupported && $env->checkIfMigrationsTableExists($env->db);

		if (!$apply && !$testRun) {
			return $this->plan()->show($namespaces, $tableExists);
		}

		if (!$apply && !$this->confirmTestRunForPending($namespaces, $tableExists, $yes)) {
			return 1;
		}

		if ($driverSupported && !$tableExists && !$this->supportsTransactions()) {
			$result = $this->createMigrationsTable();

			if ($result !== 0) {
				return $result;
			}

			$tableExists = true;
		}

		return $this->runner()->run(
			$namespaces,
			new RunOptions(
				$stacktrace,
				$apply,
				$tableExists,
				$this->createMigrationsTable(...),
			),
		);
	}

	/** @param array<string, list<string>> $namespaces */
	private function confirmTestRunForPending(array $namespaces, bool $tableExists, bool $yes): bool
	{
		$appliedMigrations = $tableExists ? $this->log()->applied($this->env->db) : [];

		foreach ($namespaces as $namespace => $migrations) {
			if ($this->planner()->pendingMigrations($namespace, $migrations, $appliedMigrations) !== []) {
				return new TestRunConfirmation()->confirm($this->io, $yes);
			}
		}

		return true;
	}

	/**
	 * The migrations of each namespace in run order. The first namespace of
	 * the order, `default` unless configured otherwise, and a namespace
	 * named on the command line must exist; a later one without migration
	 * directories has nothing to run.
	 *
	 * @param list<string> $order
	 * @return array<string, list<string>>|false
	 */
	private function namespaces(array $order, bool $required = false): array|false
	{
		$migrationNamespaces = $this->env->getMigrations();

		if ($migrationNamespaces === false) {
			$this->io->error('No migration directories defined in configuration');

			return false;
		}

		$configured = $this->env->conn->config->migrations;
		$namespaces = [];

		foreach ($order as $index => $namespace) {
			if (!array_key_exists($namespace, $migrationNamespaces)) {
				$declared = !array_is_list($configured) && array_key_exists($namespace, $configured);

				if ($index === 0 || $required || !$declared) {
					$this->namespaceMissing($namespace, $required);

					return false;
				}
			}

			$migrations = $migrationNamespaces[$namespace] ?? [];

			if (!$this->migrationIdsAreUnique($namespace, $migrations)) {
				return false;
			}

			$namespaces[$namespace] = $migrations;
		}

		return $namespaces;
	}

	private function namespaceMissing(string $namespace, bool $named): void
	{
		$this->io->error("Migration namespace '%s' does not exist", $namespace);

		if (!$named && $namespace === 'default') {
			$this->io->line(
				'If you have defined namespaced migrations, you must either provide a namespace using the '
					. "`--namespace` flag when running this command, or define a namespace named 'default' which "
					. 'will be used when no namespace is provided.',
			);
		}
	}

	/** @param list<string> $migrations */
	private function migrationIdsAreUnique(string $namespace, array $migrations): bool
	{
		$duplicates = $this->planner()->duplicateMigrationIds($namespace, $migrations);

		foreach ($duplicates as $id => $paths) {
			$this->io->error("Duplicate migration id '%s' in namespace '%s'", $id, $namespace);

			foreach ($paths as $path) {
				$this->io->stderr()->line('  - %s', $path);
			}
		}

		return count($duplicates) === 0;
	}

	private function createMigrationsTable(): int
	{
		$result = new MetadataTable($this->env, $this->io)->create($this->env->db);

		if ($result === 0) {
			return 0;
		}

		$this->io->error('Migration table could not be created.');

		return $result;
	}

	private function supportsTransactions(): bool
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
		return new Plan($this->env, $this->planner(), $this->log(), $this->io);
	}

	private function executor(): Executor
	{
		return new Executor($this->env, $this->log(), $this->phpLoader(), $this->io);
	}

	private function runner(): Runner
	{
		return new Runner(
			$this->env,
			$this->driverPolicy(),
			$this->planner(),
			$this->log(),
			$this->executor(),
			$this->io,
		);
	}
}
