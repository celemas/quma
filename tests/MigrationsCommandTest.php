<?php

declare(strict_types=1);

namespace Celema\Quma\Tests;

use Celema\Console\Args;
use Celema\Quma\Commands\CreateMigrationsTable;
use Celema\Quma\Commands\Migrations;
use Celema\Quma\Connection;
use Celema\Quma\Database;
use Celema\Quma\Environment;
use Celema\Quma\Migrations\DriverPolicy;
use Celema\Quma\Migrations\Executor;
use Celema\Quma\Migrations\Log;
use Celema\Quma\Migrations\PhpLoader;
use Celema\Quma\Migrations\Plan;
use Celema\Quma\Migrations\Planner;
use Celema\Quma\Migrations\Runner as MigrationRunner;
use Celema\Quma\Migrations\RunOptions;
use PDO;

/**
 * @internal
 */
class MigrationsCommandTest extends TestCase
{
	public function testMysqlPlanListsPendingMigrationsWithoutRunningThem(): void
	{
		$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quma-mysql-plan-' . uniqid();
		$migration = $dir . '/000001-plan.sql';
		mkdir($dir, 0o700, true);
		file_put_contents($migration, 'CREATE TABLE mysql_plan_should_not_run (id integer);');

		$_SERVER['argv'] = ['run'];
		$conn = new Connection(
			'mysql:host=localhost;dbname=quma;user=quma;password=quma',
			$this->getSqlDirs(),
		)->migrations($dir);
		$env = new Environment(['default' => $conn], []);

		try {
			ob_start();
			$result = $this->plan($env)->show('default', [$migration], false);
			$output = ob_get_contents();
			ob_end_clean();
		} finally {
			$this->removeMigrationDir($dir);
		}

		$this->assertSame(0, $result);
		$this->assertStringContainsString('Plan only', (string) $output);
		$this->assertStringContainsString("Would create migrations table 'migrations'", (string) $output);
		$this->assertStringContainsString('Would apply 1 migration', (string) $output);
		$this->assertStringContainsString('000001-plan.sql', (string) $output);
		$this->assertStringContainsString(
			'MySQL migrations are plan-only without --apply',
			(string) $output,
		);
	}

	public function testRunRefusesMysqlTestRun(): void
	{
		$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quma-mysql-test-run-' . uniqid();
		mkdir($dir, 0o700, true);
		file_put_contents(
			$dir . '/000001-test-run.sql',
			'CREATE TABLE mysql_test_run_should_not_run (id integer);',
		);

		$_SERVER['argv'] = ['run', 'migrations', '--test-run', '--yes'];
		$conn = new Connection(
			'mysql:host=localhost;dbname=quma;user=quma;password=quma',
			$this->getSqlDirs(),
		)->migrations($dir);
		$command = new Migrations($conn);
		$args = new Args(array_slice($_SERVER['argv'], offset: 2));

		try {
			ob_start();
			$result = $command->run($args);
			$output = ob_get_contents();
			ob_end_clean();
		} finally {
			$this->removeMigrationDir($dir);
		}

		$this->assertSame(1, $result);
		$this->assertStringContainsString('Test runs are only supported', (string) $output);
		$this->assertStringContainsString('implicit commits', (string) $output);
	}

	public function testMysqlApplyValidatesNamespaceBeforeOpeningConnection(): void
	{
		$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quma-mysql-validation-' . uniqid();
		mkdir($dir, 0o700, true);
		file_put_contents($dir . '/000001-validation.sql', 'SELECT 1;');

		$_SERVER['argv'] = ['run', 'migrations', '--namespace=missing', '--apply'];
		$conn = new Connection(
			'mysql:unix_socket=/path/that/does/not/exist/quma.sock;dbname=quma',
			$this->getSqlDirs(),
		)->migrations($dir);

		try {
			ob_start();
			$result = $this->consoleRunner(\Celema\Quma\Commands::get($conn))->run();
			$output = ob_get_contents();
			ob_end_clean();
		} finally {
			$this->removeMigrationDir($dir);
		}

		$this->assertSame(1, $result);
		$this->assertStringContainsString(
			"Migration namespace 'missing' does not exist",
			(string) $output,
		);
		$this->assertStringNotContainsString('SQLSTATE', (string) $output);
		$this->assertStringNotContainsString('Created table', (string) $output);
	}

	public function testRunnerCreatesMetadataBeforeMysqlApply(): void
	{
		$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quma-mysql-run-apply-' . uniqid();
		$migration = $dir . '/000001-run-apply.sql';
		mkdir($dir, 0o700, true);
		file_put_contents($migration, 'CREATE TABLE mysql_run_apply_precreate (id integer);');

		$_SERVER['argv'] = ['run'];
		$conn = $this->connection(migrations: $dir);
		$env = new Environment(['default' => $conn], []);
		$db = new Database($conn);
		$db->execute('DROP TABLE IF EXISTS migrations')->run();
		$db->execute('DROP TABLE IF EXISTS mysql_run_apply_precreate')->run();

		try {
			ob_start();
			$result = $this->runner($env, new DriverPolicy('mysql'))->run(
				'default',
				[$migration],
				new RunOptions(
					false,
					true,
					false,
					static function () use ($conn): int {
						return new CreateMigrationsTable($conn)->run(new Args([]));
					},
				),
			);
			ob_end_clean();

			$this->assertSame(0, $result);
			$this->assertSame(
				1,
				$db->execute(
					"SELECT count(*) AS available FROM sqlite_master WHERE type = 'table' AND name = 'migrations'",
				)->one(fetchMode: PDO::FETCH_ASSOC)['available'],
			);
			$this->assertSame(
				1,
				$db->execute(
					"SELECT count(*) AS available FROM sqlite_master WHERE type = 'table' AND name = 'mysql_run_apply_precreate'",
				)->one(fetchMode: PDO::FETCH_ASSOC)['available'],
			);
		} finally {
			$db->execute('DROP TABLE IF EXISTS mysql_run_apply_precreate')->run();
			$db->execute('DROP TABLE IF EXISTS migrations')->run();
			$this->removeMigrationDir($dir);
		}
	}

	public function testRunnerFinishHandlesNonTransactionalDrivers(): void
	{
		$_SERVER['argv'] = ['run'];
		$conn = $this->connection();
		$env = new Environment(['default' => $conn], []);
		$runner = $this->runner($env, new DriverPolicy('mysql'));
		$db = new Database($conn);

		ob_start();
		$resultError = $runner->finish($db, Executor::ERROR, true, 2);
		$outputError = ob_get_contents();
		ob_end_clean();

		$this->assertSame(1, $resultError);
		$this->assertStringContainsString('2 migrations applied until the error occured', $outputError);

		ob_start();
		$resultSuccess = $runner->finish($db, Executor::SUCCESS, true, 2);
		$outputSuccess = ob_get_contents();
		ob_end_clean();

		$this->assertSame(0, $resultSuccess);
		$this->assertStringContainsString('2 migrations successfully applied', $outputSuccess);

		ob_start();
		$resultEmpty = $runner->finish($db, Executor::SUCCESS, true, 0);
		$outputEmpty = ob_get_contents();
		ob_end_clean();

		$this->assertSame(0, $resultEmpty);
		$this->assertStringContainsString('No migrations applied', $outputEmpty);
	}

	private function plan(Environment $env): Plan
	{
		$policy = new DriverPolicy($env->driver);
		$planner = new Planner($policy);

		return new Plan($env, $planner, new Log($env, $planner));
	}

	private function runner(Environment $env, DriverPolicy $policy): MigrationRunner
	{
		$planner = new Planner($policy);
		$log = new Log($env, $planner);

		return new MigrationRunner(
			$env,
			$policy,
			$planner,
			$log,
			new Executor($env, $log, new PhpLoader($env)),
		);
	}

	private function removeMigrationDir(string $dir): void
	{
		$files = glob($dir . '/*');

		if (is_array($files)) {
			foreach ($files as $file) {
				if (!is_file($file)) {
					continue;
				}

				unlink($file);
			}
		}

		if (is_dir($dir)) {
			rmdir($dir);
		}
	}
}
