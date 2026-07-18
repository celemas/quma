<?php

/**
 * Migration command tests touch shared test databases, so each test resets
 * database state before arranging its own scenario.
 */

declare(strict_types=1);

namespace Celema\Quma\Tests;

use Celema\Console\Commands;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
class CreateMigrationsTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		$migrationsDir = TestCase::root() . '/migrations/';
		$paths = glob("{$migrationsDir}*test-migration*");

		if (is_array($paths)) {
			array_map('unlink', $paths);
		}

		TestCase::cleanUpTestDbs();
	}

	#[DataProvider('connectionProvider')]
	public function testCreateMigrationsTableSuccess(string $dsn): void
	{
		[$result] = $this->runCreateMigrationsTable(dsn: $dsn);

		$this->assertSame(0, $result);
	}

	#[DataProvider('connectionProvider')]
	public function testCreateMigrationsTableAlreadyExists(string $dsn): void
	{
		[$created] = $this->runCreateMigrationsTable(dsn: $dsn);
		[$result, $content] = $this->runCreateMigrationsTable(dsn: $dsn);

		$this->assertSame(0, $created);
		$this->assertSame(1, $result);

		if (str_starts_with($dsn, 'pgsql')) {
			$this->assertStringContainsString("Table 'public.migrations' already exists", $content);
		} else {
			$this->assertStringContainsString("Table 'migrations' already exists", $content);
		}
	}

	public function testCreateMigrationsTableAlreadyExistsConnectionAsArg(): void
	{
		[$created] = $this->runCreateMigrationsTable(
			multipleConnections: true,
			firstMultipleConnectionsKey: 'first',
			connectionKey: 'first',
		);
		[$result, $content] = $this->runCreateMigrationsTable(
			multipleConnections: true,
			firstMultipleConnectionsKey: 'first',
			connectionKey: 'first',
		);

		$this->assertSame(0, $created);
		$this->assertSame(1, $result);
		$this->assertStringContainsString("Table 'migrations' already exists", $content);
	}

	public function testCreateMigrationsTableAlreadyExistsMulticonnectionWithDefault(): void
	{
		[$created] = $this->runCreateMigrationsTable(
			multipleConnections: true,
			firstMultipleConnectionsKey: 'default',
		);
		[$result, $content] = $this->runCreateMigrationsTable(
			multipleConnections: true,
			firstMultipleConnectionsKey: 'default',
		);

		$this->assertSame(0, $created);
		$this->assertSame(1, $result);
		$this->assertStringContainsString("Table 'migrations' already exists", $content);
	}

	public function testCreateMigrationsTableAlternateConnection(): void
	{
		[$result] = $this->runCreateMigrationsTable(
			multipleConnections: true,
			connectionKey: 'second',
		);

		$this->assertSame(0, $result);
	}

	public function testCreateMigrationsTableAlreadyExistsAlternateConnection(): void
	{
		[$created] = $this->runCreateMigrationsTable(
			multipleConnections: true,
			connectionKey: 'second',
		);
		[$result, $content] = $this->runCreateMigrationsTable(
			multipleConnections: true,
			connectionKey: 'second',
		);

		$this->assertSame(0, $created);
		$this->assertSame(1, $result);
		$this->assertStringContainsString("Table 'migrations' already exists", $content);
	}

	/** @return array{0: string|int, 1: string} */
	private function runCreateMigrationsTable(
		?string $dsn = null,
		bool $multipleConnections = false,
		string $firstMultipleConnectionsKey = 'default',
		?string $connectionKey = null,
	): array {
		$argv = ['run', 'create-migrations-table'];

		if ($connectionKey !== null) {
			$argv[] = "--conn={$connectionKey}";
		}

		return $this->runCommand(
			$argv,
			fn(): Commands => $this->commands(
				dsn: $dsn,
				multipleConnections: $multipleConnections,
				firstMultipleConnectionsKey: $firstMultipleConnectionsKey,
			),
		);
	}

	/**
	 * @param list<string> $argv
	 * @param callable(): Commands $commandFactory
	 * @return array{0: string|int, 1: string}
	 */
	private function runCommand(array $argv, callable $commandFactory): array
	{
		$hadArgv = array_key_exists('argv', $_SERVER);
		$previousArgv = $_SERVER['argv'] ?? null;
		$level = ob_get_level();
		$_SERVER['argv'] = $argv;

		ob_start();

		try {
			$result = $this->consoleRunner($commandFactory())->run();
			$output = ob_get_clean();

			return [$result, is_string($output) ? $output : ''];
		} finally {
			while (ob_get_level() > $level) {
				ob_end_clean();
			}

			if ($hadArgv) {
				$_SERVER['argv'] = $previousArgv;
			} else {
				unset($_SERVER['argv']);
			}
		}
	}

	public static function connectionProvider(): array
	{
		return array_map(static fn($dsn) => [$dsn], TestCase::getAvailableDsns());
	}
}
