<?php

declare(strict_types=1);

namespace Celema\Quma\Tests;

use Celema\Quma\Database;
use Celema\Quma\Exception\ConnectionFailed;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
final class CredentialResolverTest extends TestCase
{
	/** @return array<string, array{string}> */
	public static function serverDriverProvider(): array
	{
		return ['mysql' => ['mysql'], 'pgsql' => ['pgsql']];
	}

	#[DataProvider('serverDriverProvider')]
	public function testEveryConnectUsesFreshlyResolvedCredentials(string $driver): void
	{
		[$dsn, $username, $password] = $this->splitDsn($driver);
		$calls = 0;
		$db = new Database($this->connection($dsn)->credentialsFrom(static function () use (
			&$calls,
			$username,
			$password,
		): array {
			$calls++;

			return [$username, $password];
		}));

		$this->assertSame(0, $calls, 'Credentials are resolved when connecting');
		$this->assertTrue($db->execute('SELECT 1')->run());
		$this->assertSame(1, $calls);

		$db->reconnect();

		$this->assertSame(2, $calls);
	}

	#[DataProvider('serverDriverProvider')]
	public function testRejectedCredentialsFailTheConnect(string $driver): void
	{
		[$dsn, $username] = $this->splitDsn($driver);
		$db = new Database($this->connection($dsn)->credentialsFrom(static fn(): array => [$username, 'expired']));

		$this->expectException(ConnectionFailed::class);

		$db->execute('SELECT 1')->run();
	}

	/**
	 * The test DSN without its credentials, and the credentials.
	 *
	 * @return array{string, string, string}
	 */
	private function splitDsn(string $driver): array
	{
		foreach (self::getAvailableDsns() as $dsn) {
			if (!str_starts_with($dsn, $driver . ':')) {
				continue;
			}

			preg_match('/;user=([^;]*)/', $dsn, $user);
			preg_match('/;password=([^;]*)/', $dsn, $password);

			return [
				(string) preg_replace('/;(user|password)=[^;]*/', '', $dsn),
				$user[1] ?? '',
				$password[1] ?? '',
			];
		}

		$this->markTestSkipped("{$driver} is not available.");
	}
}
