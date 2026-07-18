<?php

declare(strict_types=1);

namespace Celema\Quma\Migrations;

use Celema\Quma\Environment;

final readonly class Plan
{
	public function __construct(
		private Environment $env,
		private Planner $planner,
		private Log $log,
	) {}

	/** @param list<string> $migrations */
	public function show(string $namespace, array $migrations, bool $tableExists): int
	{
		$appliedMigrations = $tableExists ? $this->log->applied($this->env->db) : [];
		$pendingMigrations = $this->planner->pendingMigrations(
			$namespace,
			$migrations,
			$appliedMigrations,
		);
		$numPending = count($pendingMigrations);
		$plural = $numPending > 1 ? 's' : '';

		echo "\n\033[1;31mNotice\033[0m: Plan only\033[0m\n";

		if (!$tableExists) {
			echo "Would create migrations table '{$this->env->table}'\n";
		}

		if ($numPending === 0) {
			echo "\nNo pending migrations\n";
		} else {
			echo "Would apply {$numPending} migration{$plural}:\n";

			foreach ($pendingMigrations as $migration) {
				echo '  - ' . basename($migration) . "\n";
			}
		}

		echo "\nNo migrations were executed. ";

		if ($this->env->driver === 'mysql') {
			echo
				"MySQL migrations are plan-only without --apply because DDL statements can cause implicit commits.\n"
			;
			echo "Use --apply to run them.\n";
		} else {
			echo "Use --test-run --yes to execute inside a rollback transaction, or --apply to commit.\n";
		}

		return 0;
	}
}
