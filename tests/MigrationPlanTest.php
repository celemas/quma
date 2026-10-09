<?php

declare(strict_types=1);

namespace Celema\Quma\Tests;

use Celema\Console\Buffer;
use Celema\Console\Io;
use Celema\Quma\Environment;
use Celema\Quma\Migrations\DriverPolicy;
use Celema\Quma\Migrations\Log;
use Celema\Quma\Migrations\Plan;
use Celema\Quma\Migrations\Planner;

/**
 * @internal
 */
final class MigrationPlanTest extends TestCase
{
	public function testListsASinglePendingMigration(): void
	{
		$buffer = new Buffer();
		$io = new Io($buffer);

		$exit = $this->plan('sqlite:' . self::getDbFile(), $io)->show(
			'default',
			['/migrations/000001-users.sql'],
			false,
		);

		$this->assertSame(0, $exit);
		$this->assertSame(
			"\nNotice: Plan only\n"
				. "Would create migrations table 'migrations'\n"
				. "Would apply 1 migration:\n"
				. "  - 000001-users.sql\n"
				. "\nNo migrations were executed. "
				. "Use --test-run --yes to execute inside a rollback transaction, or --apply to commit.\n",
			$buffer->output(),
		);
	}

	public function testMysqlPlanPointsToApply(): void
	{
		$buffer = new Buffer();
		$io = new Io($buffer);

		$this->plan('mysql:host=localhost;dbname=quma', $io)->show('default', [], false);

		$this->assertStringEndsWith(
			"\nNo migrations were executed. "
				. "MySQL migrations are plan-only without --apply because DDL statements can cause implicit commits.\n"
				. "Use --apply to run them.\n",
			$buffer->output(),
		);
	}

	private function plan(string $dsn, Io $io): Plan
	{
		$_SERVER['argv'] = ['run'];
		$env = new Environment(['default' => $this->connection(dsn: $dsn)->migrationTable('migrations')], []);
		$planner = new Planner(new DriverPolicy($env->driver));

		return new Plan($env, $planner, new Log($env, $planner), $io);
	}
}
