<?php

declare(strict_types=1);

namespace Celema\Quma\Tests;

use Celema\Quma\Database;
use Celema\Quma\Tests\Util\InspectableDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
final class OnConnectTest extends TestCase
{
	/** @return array<string, array{string, string, string, string}> */
	public static function sessionSettingProvider(): array
	{
		return [
			'sqlite' => ['sqlite', 'PRAGMA foreign_keys = ON', 'PRAGMA foreign_keys', '1'],
			'pgsql' => ['pgsql', "SET application_name = 'quma-hook'", 'SHOW application_name', 'quma-hook'],
			'mysql' => ['mysql', "SET @quma_hook = 'set'", 'SELECT @quma_hook', 'set'],
		];
	}

	#[DataProvider('sessionSettingProvider')]
	public function testTheHookSetsUpEveryNewConnection(
		string $driver,
		string $setting,
		string $read,
		string $expected,
	): void {
		$calls = 0;
		$conn = $this->connection($this->dsn($driver))->onConnect(static function (Database $db) use (
			&$calls,
			$setting,
		): void {
			$calls++;
			$db->execute($setting)->run();
		});
		$db = new Database($conn);

		$this->assertSame(0, $calls, 'Creating the database does not connect');
		$this->assertSame($expected, $this->value($db, $read));
		$this->assertSame(1, $calls);

		$db->reconnect();
		$this->assertSame(2, $calls);
		$this->assertSame($expected, $this->value($db, $read));
		$this->assertSame(2, $calls, 'An open connection is not set up again');
	}

	public function testTheHookRunsForAConnectionThatReplacesAnOldOne(): void
	{
		$calls = 0;
		$db = new InspectableDatabase($this
			->connection()
			->maxConnectionAge(30)
			->onConnect(static function () use (&$calls): void {
				$calls++;
			}));
		$pdo = $db->getConn();
		$db->setTimesPublic(time() - 31, time());

		$this->assertNotSame($pdo, $db->getConn());
		$this->assertSame(2, $calls);
	}

	public function testAFailingHookDropsTheConnectionAndFailsTheStatement(): void
	{
		$failure = new LogicException('Setup failed');
		$fail = true;
		$db = new Database($this->connection()->onConnect(static function () use (&$fail, $failure): void {
			if ($fail) {
				throw $failure;
			}
		}));

		try {
			$db->execute('SELECT 1')->run();
			$this->fail('Expected the exception of the hook.');
		} catch (LogicException $e) {
			$this->assertSame($failure, $e);
		}

		$this->assertFalse($db->connected());

		$fail = false;
		$this->assertTrue($db->execute('SELECT 1')->run());
		$this->assertTrue($db->connected());
	}

	private function value(Database $db, string $sql): string
	{
		return (string) array_values($db->execute($sql)->one())[0];
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
