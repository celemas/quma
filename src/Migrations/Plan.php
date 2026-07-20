<?php

declare(strict_types=1);

namespace Celema\Quma\Migrations;

use Celema\Console\Io;
use Celema\Quma\Environment;

final readonly class Plan
{
	public function __construct(
		private Environment $env,
		private Planner $planner,
		private Log $log,
		private Io $io,
	) {}

	/** @param list<string> $migrations */
	public function show(string $namespace, array $migrations, bool $tableExists): int
	{
		$io = $this->io;
		$appliedMigrations = $tableExists ? $this->log->applied($this->env->db) : [];
		$pendingMigrations = $this->planner->pendingMigrations(
			$namespace,
			$migrations,
			$appliedMigrations,
		);
		$numPending = count($pendingMigrations);
		$plural = $numPending > 1 ? 's' : '';

		$io->echoln("\n<bright-red>Notice</bright-red>: Plan only");

		if (!$tableExists) {
			$io->echoln("Would create migrations table '{$this->env->table}'");
		}

		if ($numPending === 0) {
			$io->echoln("\nNo pending migrations");
		} else {
			$io->echoln("Would apply {$numPending} migration{$plural}:");

			foreach ($pendingMigrations as $migration) {
				$io->echoln('  - ' . basename($migration));
			}
		}

		$io->echo("\nNo migrations were executed. ");

		if ($this->env->driver === 'mysql') {
			$io->echoln(
				'MySQL migrations are plan-only without --apply because DDL statements can cause implicit commits.',
			);
			$io->echoln('Use --apply to run them.');
		} else {
			$io->echoln(
				'Use --test-run --yes to execute inside a rollback transaction, or --apply to commit.',
			);
		}

		return 0;
	}
}
