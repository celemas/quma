<?php

declare(strict_types=1);

namespace Celema\Quma\Migrations;

use Celema\Console\Io;
use Celema\Quma\Database;
use Celema\Quma\Environment;

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

	/** @param list<string> $migrations */
	public function run(string $namespace, array $migrations, RunOptions $options): int
	{
		$db = $this->env->db;

		$this->begin($db);

		if (!$options->tableExists) {
			$result = ($options->createMigrationsTable)();

			// @codeCoverageIgnoreStart
			if ($result !== 0) {
				if ($this->driverPolicy->supportsTransactions()) {
					$db->rollback();
				}

				return $result;
			} // @codeCoverageIgnoreEnd
		}

		$appliedMigrations = $this->log->applied($db);
		$result = Executor::STARTED;
		$numApplied = 0;

		foreach ($migrations as $migration) {
			assert($migration !== '', 'Migration path must be a non-empty string.');

			$migrationId = $this->planner->migrationId($namespace, $migration);

			if (in_array($migrationId, $appliedMigrations, strict: true)) {
				continue;
			}

			if (!$this->driverPolicy->supportsMigration($migration)) {
				continue;
			}

			$result = $this->executor->migrate($namespace, $migration, $options->showStacktrace);

			if ($result === Executor::ERROR) {
				break;
			}

			if ($result === Executor::SUCCESS) {
				$numApplied++;
			}
		}

		return $this->finish($db, $result, $options->apply, $numApplied);
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
				$io->echolnErr("\nDue to errors no migrations applied");

				return 1;
			}

			if ($numApplied === 0) {
				$db->rollback();
				$io->echoln("\nNo migrations applied");

				return 0;
			}

			if ($apply) {
				$db->commit();
				$io->echoln("\n{$numApplied} migration{$plural} successfully applied");

				return 0;
			}
			$io->echoln("\n<bright-red>Notice</bright-red>: Test run only");
			$io->echo("Rolled back {$numApplied} migration{$plural}. ");
			$io->echoln('Use --apply to commit them');
			$db->rollback();

			return 0;
		}

		if ($result === Executor::ERROR) {
			$io->echolnErr("\n{$numApplied} migration{$plural} applied until the error occured");

			return 1;
		}

		if ($numApplied > 0) {
			$io->echoln("\n{$numApplied} migration{$plural} successfully applied");

			return 0;
		}

		$io->echoln("\nNo migrations applied");

		return 0;
	}

	private function begin(Database $db): void
	{
		if ($this->driverPolicy->supportsTransactions()) {
			$db->begin();
		}
	}
}
