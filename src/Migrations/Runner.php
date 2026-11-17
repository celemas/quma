<?php

declare(strict_types=1);

namespace Celema\Quma\Migrations;

use Celema\Console\Io;
use Celema\Quma\Database;
use Celema\Quma\Environment;
use Throwable;

final readonly class Runner
{
	// Internal wiring, constructed by the Migrations command only.
	// @mago-expect lint:excessive-parameter-list
	public function __construct(
		private Environment $env,
		private DriverPolicy $driverPolicy,
		private Planner $planner,
		private Log $log,
		private Executor $executor,
		private Io $io,
	) {}

	/**
	 * Runs the pending migrations of several namespaces, in the given order,
	 * in one transaction where the driver allows it.
	 *
	 * @param array<string, list<string>> $namespaces
	 */
	public function run(array $namespaces, RunOptions $options): int
	{
		$db = $this->env->db;
		$lock = new Lock($this->env);
		$lock->begin($db);

		try {
			return $this->runLocked($db, $namespaces, $options);
		} finally {
			$lock->release($db);
		}
	}

	/** @param array<string, list<string>> $namespaces */
	private function runLocked(Database $db, array $namespaces, RunOptions $options): int
	{
		// Another run may have created the table while this one waited for the lock.
		if (!$this->env->checkIfMigrationsTableExists($db)) {
			$result = ($options->createMigrationsTable)();

			if ($result !== 0) {
				if ($this->driverPolicy->supportsTransactions()) {
					$db->rollback();
				}

				return $result;
			}
		}

		$appliedMigrations = $this->log->applied($db);
		$result = Executor::STARTED;
		$applied = [];

		foreach ($namespaces as $namespace => $migrations) {
			$prepared = false;

			foreach ($migrations as $migration) {
				$migrationId = $this->planner->migrationId($namespace, $migration);

				if (in_array($migrationId, $appliedMigrations, strict: true)) {
					continue;
				}

				if (!$this->driverPolicy->supportsMigration($migration)) {
					continue;
				}

				if (!$prepared) {
					$prepared = true;

					if (!$this->prepare($namespace, $options->showStacktrace)) {
						$result = Executor::ERROR;

						break 2;
					}
				}

				$result = $this->executor->migrate($namespace, $migration, $options->showStacktrace);

				if ($result === Executor::ERROR) {
					break 2;
				}

				if ($result === Executor::SUCCESS) {
					$applied[] = $migrationId;
				}
			}
		}

		if ($result !== Executor::ERROR && $applied !== [] && !$this->complete($applied, $options->showStacktrace)) {
			$result = Executor::ERROR;
		}

		return $this->finish($db, $result, $options->apply, count($applied));
	}

	public function finish(
		Database $db,
		string $result,
		bool $apply,
		int $numApplied,
	): int {
		$io = $this->io;
		$plural = $numApplied > 1 ? 's' : '';

		if ($this->driverPolicy->supportsTransactions()) {
			if ($result === Executor::ERROR) {
				$db->rollback();
				$io->error("\nDue to errors no migrations applied");

				return 1;
			}

			if ($numApplied === 0) {
				$db->rollback();
				$io->line("\nNo migrations applied");

				return 0;
			}

			if ($apply) {
				$db->commit();
				$io->line("\n{$numApplied} migration{$plural} successfully applied");

				return 0;
			}
			$io->line("\n<bright-red>Notice</bright-red>: Test run only");
			$io->write("Rolled back {$numApplied} migration{$plural}. ");
			$io->line('Use --apply to commit them');
			$db->rollback();

			return 0;
		}

		if ($result === Executor::ERROR) {
			$io->error("\n{$numApplied} migration{$plural} applied until the error occured");

			return 1;
		}

		if ($numApplied > 0) {
			$io->line("\n{$numApplied} migration{$plural} successfully applied");

			return 0;
		}

		$io->line("\nNo migrations applied");

		return 0;
	}

	private function prepare(string $namespace, bool $showStacktrace): bool
	{
		$hook = $this->env->conn->beforeMigrationsHook;

		if ($hook === null) {
			return true;
		}

		try {
			$hook($this->env, $namespace);

			return true;
		} catch (Throwable $e) {
			$this->io->error(
				"Error: while preparing the migrations of namespace '<bright-yellow>%s</bright-yellow>'",
				$namespace,
			);
			$this->hookFailed($e, $showStacktrace);

			return false;
		}
	}

	/** @param list<string> $applied */
	private function complete(array $applied, bool $showStacktrace): bool
	{
		$hook = $this->env->conn->afterMigrationsHook;

		if ($hook === null) {
			return true;
		}

		try {
			$hook($this->env, $applied);

			return true;
		} catch (Throwable $e) {
			$this->io->error('Error: while completing the migrations');
			$this->hookFailed($e, $showStacktrace);

			return false;
		}
	}

	private function hookFailed(Throwable $e, bool $showStacktrace): void
	{
		$this->io->stderr()->line('%s', $e->getMessage());

		if ($showStacktrace) {
			$this->io->stderr()->line('%s', $e->getTraceAsString());
		}
	}
}
