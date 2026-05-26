<?php

declare(strict_types=1);

namespace Celemas\Quma\Migrations;

use Celemas\Quma\Database;
use Celemas\Quma\Environment;

final readonly class Runner
{
	public function __construct(
		private Environment $env,
		private DriverPolicy $driverPolicy,
		private Planner $planner,
		private Log $log,
		private Executor $executor,
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
		$plural = $numApplied > 1 ? 's' : '';

		if ($this->driverPolicy->supportsTransactions()) {
			if ($result === Executor::ERROR) {
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
			echo "\nRolled back {$numApplied} migration{$plural}. ";
			echo "Use --apply to commit them\n";
			$db->rollback();

			return 0;
		}

		if ($result === Executor::ERROR) {
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

	private function begin(Database $db): void
	{
		if ($this->driverPolicy->supportsTransactions()) {
			$db->begin();
		}
	}
}
