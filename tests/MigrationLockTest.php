<?php

declare(strict_types=1);

namespace Celema\Quma\Tests;

use Celema\Console\Buffer;
use Celema\Console\Io;
use Celema\Quma\Database;
use Celema\Quma\Environment;
use Celema\Quma\Migrations\DriverPolicy;
use Celema\Quma\Migrations\Lock;
use Celema\Quma\Migrations\Log;
use Celema\Quma\Migrations\MetadataTable;
use Celema\Quma\Migrations\Planner;
use Celema\Quma\Tests\Util\FakeDatabase;
use PDO;
use Pdo\Sqlite;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * @internal
 */
final class MigrationLockTest extends TestCase
{
	/** @return array<string, array{0: string, 1?: string}> */
	public static function drivers(): array
	{
		return [
			'sqlite' => ['sqlite'],
			'pgsql' => ['pgsql'],
			// The waiting run must not read the applied migrations from a snapshot taken before the lock.
			'pgsql, repeatable read by default' => ['pgsql', 'repeatable read'],
			'mysql' => ['mysql'],
		];
	}

	#[DataProvider('drivers')]
	public function testARunWaitsForTheRunHoldingTheLockAndSeesItsWork(string $driver, string $isolation = ''): void
	{
		$dsn = $this->dsn($driver);
		$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quma-lock-' . uniqid();
		mkdir($dir, 0o700);
		$migration = $dir . '/000001-locked.sql';
		file_put_contents($migration, 'CREATE TABLE quma_locked (id integer);');
		$conn = $this->connection(dsn: $dsn, migrations: $dir);
		$env = new Environment(['default' => $conn], []);
		$db = $env->db;
		$this->dropTables($conn);

		new Lock($env)->begin($db);
		$child = proc_open(
			[PHP_BINARY, __DIR__ . '/Util/migrate.php', $dsn, $dir, $env->table, $isolation],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
		);
		$this->assertIsResource($child);

		try {
			$this->waitUntilWaiting($driver, $child);

			// What the run holding the lock applies, the waiting one must see.
			new MetadataTable($env, new Io(new Buffer()))->create($db);
			$db->execute('CREATE TABLE quma_locked (id integer)')->run();
			new Log($env, new Planner(new DriverPolicy($driver)))->record($db, 'default', $migration);

			if ($driver !== 'mysql') {
				$db->commit();
			}

			new Lock($env)->release($db);
			$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

			$this->assertSame(0, proc_close($child), $output);
			$this->assertStringContainsString('No migrations applied', $output);
			$this->assertStringNotContainsString('Created table', $output);
		} finally {
			$db->disconnect();
			$this->dropTables($conn);
			unlink($migration);
			rmdir($dir);
		}
	}

	public function testAPostgresqlRunReadsCommittedDataWhateverTheDefault(): void
	{
		$conn = $this->connection(dsn: $this->dsn('pgsql'))->onConnect(static function (Database $db): void {
			$db->execute("SET default_transaction_isolation = 'repeatable read'")->run();
		});
		$env = new Environment(['default' => $conn], []);
		$db = $env->db;

		new Lock($env)->begin($db);

		try {
			$this->assertSame(['read committed'], array_values($db->execute('SHOW transaction_isolation')->one()));
		} finally {
			$db->rollback();
			$db->disconnect();
		}
	}

	public function testRejectsAnUnsupportedDriver(): void
	{
		$conn = $this->connection();
		$lock = new Lock(new Environment(['default' => $conn], []));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Database driver not supported');

		$lock->begin(new FakeDatabase($conn, 'odbc', 0));
	}

	public function testAMysqlRunStopsWhenItGetsNoLock(): void
	{
		$conn = $this->connection();
		$lock = new Lock(new Environment(['default' => $conn], []));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Could not lock the migrations table 'migrations'");

		$lock->begin(new FakeDatabase($conn, 'mysql', 0));
	}

	public function testAFailedBeginLeavesLaterTransactionsDeferred(): void
	{
		$env = new Environment(['default' => $this->connection()], []);
		$env->db->begin();

		try {
			new Lock($env)->begin($env->db);
			$this->fail('Expected the begin to fail inside an open transaction.');
		} catch (PDOException) {
			$this->addToAssertionCount(1);
		} finally {
			$env->db->rollback();
		}

		$this->assertSame(
			Sqlite::TRANSACTION_MODE_DEFERRED,
			$env->db->getConn()->getAttribute(Sqlite::ATTR_TRANSACTION_MODE),
		);
	}

	/** @param resource $child */
	private function waitUntilWaiting(string $driver, $child): void
	{
		if ($driver === 'sqlite') {
			// SQLite shows no waiting connections; without the lock the run would fail early.
			usleep(1_000_000);
			$this->assertTrue(proc_get_status($child)['running']);

			return;
		}

		$observer = new PDO($this->dsn($driver));
		$query = $driver === 'pgsql'
			? "SELECT count(*) FROM pg_stat_activity WHERE wait_event_type = 'Lock' AND wait_event = 'advisory'"
			: "SELECT count(*) FROM information_schema.PROCESSLIST WHERE STATE = 'User lock'";

		for ($i = 0; $i < 500; $i++) {
			if ((int) $observer->query($query)->fetchColumn() > 0) {
				return;
			}

			usleep(20_000);
		}

		$this->fail('The second run did not wait for the lock.');
	}

	private function dropTables(\Celema\Quma\Connection $conn): void
	{
		$db = new Database($conn);
		$db->execute('DROP TABLE IF EXISTS quma_locked')->run();
		$db->execute('DROP TABLE IF EXISTS ' . $conn->config->migrationsTable)->run();
		$db->disconnect();
	}

	private function dsn(string $driver): string
	{
		foreach (self::getAvailableDsns() as $dsn) {
			if (str_starts_with($dsn, $driver . ':')) {
				return $dsn;
			}
		}

		$this->markTestSkipped("{$driver} is not available.");
	}
}
