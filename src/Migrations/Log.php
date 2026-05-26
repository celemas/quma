<?php

declare(strict_types=1);

namespace Celemas\Quma\Migrations;

use Celemas\Quma\Database;
use Celemas\Quma\Environment;

final readonly class Log
{
	public function __construct(
		private Environment $env,
		private Planner $planner,
	) {}

	/** @return list<string> */
	public function applied(Database $db): array
	{
		$table = $this->env->table;
		$column = $this->env->columnMigration;
		$migrations = $db->execute("SELECT {$column} AS migration FROM {$table};")->all();

		return array_map(
			static fn(array $migration): string => (string) $migration['migration'],
			$migrations,
		);
	}

	public function record(Database $db, string $namespace, string $migration): void
	{
		$table = $this->env->table;
		$column = $this->env->columnMigration;

		$db->execute(
			"INSERT INTO {$table} ({$column}) VALUES (:migration)",
			['migration' => $this->planner->migrationId($namespace, $migration)],
		)->run();
	}
}
