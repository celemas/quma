<?php

declare(strict_types=1);

namespace Celema\Quma\Tests;

use Celema\Quma\Database;
use Celema\Quma\Tests\Util\BrokenPdo;
use Celema\Quma\Tests\Util\FlakyPdo;
use Celema\Quma\Tests\Util\InspectableDatabase;
use Closure;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use ValueError;

/**
 * A long-running process (a worker, a queue consumer) keeps one Database
 * for many units of work and resets it in between.
 *
 * @internal
 */
final class ConnectionReuseTest extends TestCase
{
	public static function setUpBeforeClass(): void
	{
		self::createTestDb();
	}

	public function testResetRollsBackAndKeepsTheConnection(): void
	{
		$db = $this->getDb();
		$pdo = $db->getConn();
		$db->begin();
		$db->members->add('Tim Aymar', 1998, 2001)->run();

		$this->assertTrue($db->reset());
		$this->assertSame($pdo, $db->getConn());
		$this->assertFalse($pdo->inTransaction());
		$this->assertCount(DatabaseTest::NUMBER_OF_MEMBERS, $db->members->list()->all());
	}

	public function testResetWithoutConnectionKeepsNothing(): void
	{
		$db = new Database($this->connection());

		$this->assertFalse($db->reset());
		$this->assertFalse($db->connected());
	}

	public function testResetDropsAConnectionThatCannotRollBack(): void
	{
		$db = new InspectableDatabase($this->connection());
		$broken = new BrokenPdo(inTransaction: true);
		$db->setPdoPublic($broken);

		$this->assertFalse($db->reset());
		$this->assertFalse($db->connected());
		$this->assertNotSame($broken, $db->getConn());
	}

	public function testResetWithoutRollbackDoesNotCountAsUse(): void
	{
		$db = new InspectableDatabase($this->connection()->pingAfterIdle(30));
		$broken = new BrokenPdo();
		$db->setPdoPublic($broken);
		$db->setTimesPublic(time() - 60, time() - 31);

		$this->assertTrue($db->reset());
		$this->assertNotSame($broken, $db->getConn());
	}

	public function testBrokenIdleConnectionIsReplacedBeforeReuse(): void
	{
		$db = new InspectableDatabase($this->connection()->pingAfterIdle(30));
		$broken = new BrokenPdo();
		$db->setPdoPublic($broken);
		$db->setTimesPublic(time() - 60, time() - 31);

		$this->assertNotSame($broken, $db->getConn());
		$this->assertCount(DatabaseTest::NUMBER_OF_MEMBERS, $db->members->list()->all());
	}

	public function testHealthyIdleConnectionIsKept(): void
	{
		$db = new InspectableDatabase($this->connection()->pingAfterIdle(30));
		$pdo = $db->getConn();
		$db->setTimesPublic(time() - 60, time() - 31);

		$this->assertSame($pdo, $db->getConn());
		$this->assertGreaterThanOrEqual(time() - 1, $db->lastUsedAtPublic());
	}

	public function testRecentlyUsedConnectionIsNotPinged(): void
	{
		$db = new InspectableDatabase($this->connection()->pingAfterIdle(30));
		$broken = new BrokenPdo();
		$db->setPdoPublic($broken);
		$db->setTimesPublic(time() - 60, time() - 5);

		$this->assertSame($broken, $db->getConn());
	}

	public function testIdleCheckCanBeDisabled(): void
	{
		$db = new InspectableDatabase($this->connection()->pingAfterIdle(0));
		$broken = new BrokenPdo();
		$db->setPdoPublic($broken);
		$db->setTimesPublic(time() - 3600, time() - 3600);

		$this->assertSame($broken, $db->getConn());
	}

	public function testConnectionInsideATransactionIsNeverReplaced(): void
	{
		$db = new InspectableDatabase($this->connection()->pingAfterIdle(30)->maxConnectionAge(30));
		$broken = new BrokenPdo(inTransaction: true);
		$db->setPdoPublic($broken);
		$db->setTimesPublic(time() - 60, time() - 60);

		$this->assertSame($broken, $db->getConn());
	}

	public function testConnectionOlderThanTheMaximumAgeIsReplaced(): void
	{
		$db = new InspectableDatabase($this->connection()->maxConnectionAge(30));
		$pdo = $db->getConn();
		$db->setTimesPublic(time() - 31, time());

		$this->assertNotSame($pdo, $db->getConn());
	}

	public function testQueryBuiltBeforeTheConnectionWasReplacedRunsInItsTransaction(): void
	{
		$db = new InspectableDatabase($this->connection()->maxConnectionAge(30));
		$insert = $db->members->add('Tim Aymar', 1998, 2001);
		$db->setTimesPublic(time() - 31, time());
		$db->begin();
		$insert->run();
		$db->rollback();

		$this->assertCount(DatabaseTest::NUMBER_OF_MEMBERS, $db->members->list()->all());
	}

	public function testFetchingContinuesWhenTheConnectionAgesInBetween(): void
	{
		$db = new InspectableDatabase($this->connection()->maxConnectionAge(30));
		$query = $db->members->list();
		$first = $query->fetch();
		$db->setTimesPublic(time() - 31, time());
		$second = $query->fetch();

		$this->assertNotNull($first);
		$this->assertNotNull($second);
		$this->assertNotEquals($first, $second);
	}

	/** @param Closure(Database, FlakyPdo): mixed $operation */
	#[DataProvider('lostConnectionProvider')]
	public function testResetReplacesAConnectionLostWhile(Closure $operation): void
	{
		$db = new InspectableDatabase($this->connection());
		$pdo = new FlakyPdo();
		$db->setPdoPublic($pdo);

		$this->assertPdoFailure(static fn() => $operation($db, $pdo));
		$this->assertFalse($db->reset());
		$this->assertNotSame($pdo, $db->getConn());
	}

	/** @return array<string, array{Closure(Database, FlakyPdo): mixed}> */
	public static function lostConnectionProvider(): array
	{
		return [
			'preparing' => [
				static function (Database $db, FlakyPdo $pdo): void {
					$pdo->loseConnection();
					$db->execute('SELECT 1');
				},
			],
			'executing' => [
				static function (Database $db, FlakyPdo $pdo): void {
					$query = $db->execute('SELECT 1');
					$pdo->loseConnection();
					$query->run();
				},
			],
			'fetching a row' => [
				static function (Database $db, FlakyPdo $pdo): void {
					$pdo->loseConnectionAfterExecute();
					$db->execute('SELECT 1')->fetch();
				},
			],
			'fetching all rows' => [
				static function (Database $db, FlakyPdo $pdo): void {
					$pdo->loseConnectionAfterExecute();
					$db->execute('SELECT 1')->all();
				},
			],
			'iterating rows' => [
				static function (Database $db, FlakyPdo $pdo): void {
					$pdo->loseConnectionAfterExecute();
					iterator_to_array($db->execute('SELECT 1')->lazy());
				},
			],
			'beginning a transaction' => [
				static function (Database $db, FlakyPdo $pdo): void {
					$pdo->loseConnection();
					$db->begin();
				},
			],
		];
	}

	public function testSqlErrorKeepsTheConnection(): void
	{
		$db = $this->getDb();
		$pdo = $db->getConn();

		$this->assertPdoFailure(static fn() => $db->execute('SELECT * FROM missing_table')->all());
		$this->assertTrue($db->reset());
		$this->assertSame($pdo, $db->getConn());
	}

	public function testFailureOnAReplacedConnectionLeavesTheCurrentOneAlone(): void
	{
		$db = new InspectableDatabase($this->connection());
		$old = new FlakyPdo();
		$db->setPdoPublic($old);
		$query = $db->execute('SELECT 1 UNION ALL SELECT 2');
		$query->fetch();
		// A ping would fail on this connection: keeping it shows that none was sent.
		$current = new BrokenPdo();
		$db->setPdoPublic($current);
		$old->loseConnection();

		$this->assertPdoFailure($query->fetch(...));
		$this->assertTrue($db->reset());
		$this->assertSame($current, $db->getConn());
	}

	public function testQueryThatFailedToPrepareOnANewConnectionIsPreparedAgain(): void
	{
		$db = new InspectableDatabase($this->connection());
		$query = $db->members->list();
		$lost = new FlakyPdo();
		$lost->loseConnection();
		$db->setPdoPublic($lost);

		$this->assertPdoFailure($query->all(...));
		// Must not fall back to the statement prepared on the replaced connection.
		$this->assertPdoFailure($query->all(...));
	}

	#[DataProvider('serverDriverProvider')]
	public function testResetReplacesAConnectionTheServerClosed(string $driver): void
	{
		$db = new Database($this->connection($this->serverDsn($driver)));
		$pdo = $db->getConn();
		$this->closeOnServer($pdo, $driver);

		$this->assertPdoFailure(static fn() => $db->execute('SELECT 1')->run());
		$this->assertFalse($db->reset());
		$this->assertNotSame($pdo, $db->getConn());
		$this->assertTrue($db->execute('SELECT 1')->run());
	}

	#[DataProvider('serverDriverProvider')]
	public function testLostTransactionIsNotContinuedOnANewConnection(string $driver): void
	{
		$db = new Database($this->connection($this->serverDsn($driver)));
		$db->begin();
		$this->closeOnServer($db->getConn(), $driver);

		$this->assertPdoFailure(static fn() => $db->execute('SELECT 1')->run());
		$this->assertPdoFailure(static fn() => $db->execute('SELECT 1')->run());
		$this->assertFalse($db->reset());
	}

	/** @return array<string, array{string}> */
	public static function serverDriverProvider(): array
	{
		return ['mysql' => ['mysql'], 'pgsql' => ['pgsql']];
	}

	public function testDefaultsPingAfterAMinuteAndKeepConnectionsRegardlessOfAge(): void
	{
		$config = $this->connection()->config;

		$this->assertSame(60, $config->pingAfterIdle);
		$this->assertSame(0, $config->maxConnectionAge);
	}

	public function testNegativeIdleThresholdIsRejected(): void
	{
		$this->expectException(ValueError::class);
		$this->expectExceptionMessage('pingAfterIdle');

		$this->connection()->pingAfterIdle(-1);
	}

	public function testNegativeMaximumAgeIsRejected(): void
	{
		$this->expectException(ValueError::class);
		$this->expectExceptionMessage('maxConnectionAge');

		$this->connection()->maxConnectionAge(-1);
	}

	/** @param Closure(): mixed $operation */
	private function assertPdoFailure(Closure $operation): void
	{
		try {
			$operation();
		} catch (PDOException) {
			$this->addToAssertionCount(1);

			return;
		}

		$this->fail('The operation did not fail.');
	}

	private function serverDsn(string $driver): string
	{
		foreach (self::getAvailableDsns() as $dsn) {
			if (str_starts_with($dsn, $driver . ':')) {
				return $dsn;
			}
		}

		$this->markTestSkipped("{$driver} is not available.");
	}

	/** Ends the connection's session from a second connection, as a server restart would. */
	private function closeOnServer(PDO $pdo, string $driver): void
	{
		$admin = new PDO($this->serverDsn($driver));

		if ($driver === 'pgsql') {
			$pid = (int) $pdo->query('SELECT pg_backend_pid()')->fetchColumn();
			// Waits up to five seconds for the session to end.
			$admin->query("SELECT pg_terminate_backend({$pid}, 5000)");

			return;
		}

		$id = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
		$admin->exec("KILL {$id}");
		$open = $admin->prepare('SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = ?');

		// KILL returns before the session has ended.
		for ($i = 0; $i < 500; $i++) {
			$open->execute([$id]);

			if ((int) $open->fetchColumn() === 0) {
				return;
			}

			usleep(10_000);
		}

		$this->fail('The MySQL session did not end.');
	}
}
