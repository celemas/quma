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

	/**
	 * Lists the pending migrations of several namespaces, in run order, by
	 * the id each is recorded with.
	 *
	 * @param array<string, list<string>> $namespaces
	 */
	public function show(array $namespaces, bool $tableExists): int
	{
		$io = $this->io;
		$appliedMigrations = $tableExists ? $this->log->applied($this->env->db) : [];
		$pendingIds = [];

		foreach ($namespaces as $namespace => $migrations) {
			foreach ($this->planner->pendingMigrations($namespace, $migrations, $appliedMigrations) as $migration) {
				$pendingIds[] = $this->planner->migrationId($namespace, $migration);
			}
		}

		$numPending = count($pendingIds);
		$plural = $numPending > 1 ? 's' : '';

		$io->line("\n<bright-red>Notice</bright-red>: Plan only");

		if (!$tableExists) {
			$io->line("Would create migrations table '%s'", $this->env->table);
		}

		if ($numPending === 0) {
			$io->line("\nNo pending migrations");
		} else {
			$io->line("Would apply {$numPending} migration{$plural}:");

			foreach ($pendingIds as $id) {
				$io->line('  - %s', $id);
			}
		}

		$io->write("\nNo migrations were executed. ");

		if ($this->env->driver === 'mysql') {
			$io->line(
				'MySQL migrations are plan-only without --apply because DDL statements can cause implicit commits.',
			);
			$io->line('Use --apply to run them.');
		} else {
			$io->line('Use --test-run --yes to execute inside a rollback transaction, or --apply to commit.');
		}

		return 0;
	}
}
