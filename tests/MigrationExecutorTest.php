<?php

declare(strict_types=1);

namespace Celemas\Quma\Tests;

use Celemas\Quma\Database;
use Celemas\Quma\Environment;
use Celemas\Quma\Migrations\DriverPolicy;
use Celemas\Quma\Migrations\Executor;
use Celemas\Quma\Migrations\Log;
use Celemas\Quma\Migrations\PhpLoader;
use Celemas\Quma\Migrations\Planner;

/**
 * @internal
 */
class MigrationExecutorTest extends TestCase
{
	public function testReportsUnreadableMigration(): void
	{
		$missing = sys_get_temp_dir() . '/missing-migration-' . uniqid() . '.tpql';
		if (is_file($missing)) {
			unlink($missing);
		}

		$handler = set_error_handler(static fn(): bool => true);
		try {
			ob_start();
			$result = $this->executor()->migrate('default', $missing, false);
			$output = ob_get_contents();
			ob_end_clean();
		} finally {
			if ($handler !== null) {
				restore_error_handler();
			}
		}

		$this->assertSame(Executor::ERROR, $result);
		$this->assertStringContainsString('Could not read migration file', (string) $output);
	}

	public function testLogRecordsAndReadsMigrations(): void
	{
		$_SERVER['argv'] = ['run'];
		$conn = $this->connection();
		$db = new Database($conn);
		$db->execute('DROP TABLE IF EXISTS migrations')->run();
		$db->execute('CREATE TABLE migrations (migration text, applied text)')->run();
		$log = $this->log(new Environment(['default' => $conn], []));

		try {
			$log->record($db, 'cms', '/migrations/000001-users.sql');

			$this->assertSame(['cms:000001-users.sql'], $log->applied($db));
		} finally {
			$db->execute('DROP TABLE IF EXISTS migrations')->run();
		}
	}

	private function executor(): Executor
	{
		$_SERVER['argv'] = ['run'];
		$env = new Environment(['default' => $this->connection()], []);

		return new Executor($env, $this->log($env), new PhpLoader($env));
	}

	private function log(Environment $env): Log
	{
		return new Log($env, new Planner(new DriverPolicy($env->driver)));
	}
}
