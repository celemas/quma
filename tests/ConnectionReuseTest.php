<?php

declare(strict_types=1);

namespace Celema\Quma\Tests;

use Celema\Quma\Database;
use Celema\Quma\Tests\Util\BrokenPdo;
use Celema\Quma\Tests\Util\InspectableDatabase;
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
}
