<?php

declare(strict_types=1);

namespace Celema\Quma\Tests;

use Celema\Quma\Database;
use Celema\Quma\Exception\MissingParameter;
use Celema\Quma\LoadedScript;
use Celema\Quma\Script;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
final class MissingParameterTest extends TestCase
{
	/** @param list<mixed> $args */
	#[DataProvider('missingNamed')]
	public function testNamesAMissingParameter(string $sql, array $args): void
	{
		foreach (self::getAvailableDsns() as $dsn) {
			$db = new Database($this->connection(dsn: $dsn));

			try {
				$db->execute($sql, ...$args);
				$this->fail("Expected a MissingParameter on {$dsn}");
			} catch (MissingParameter $e) {
				$this->assertSame('Missing parameter :x.', $e->getMessage(), $dsn);
				$this->assertNull($e->script);
			}
		}
	}

	/** @return array<string, array{string, list<mixed>}> */
	public static function missingNamed(): array
	{
		return [
			'no arguments' => ['SELECT :x AS v', []],
			'empty array' => ['SELECT :x AS v', [[]]],
			'other name' => ['SELECT :x AS v, :y AS w', [['y' => 1]]],
			'at the start' => [':x', []],
			'after quote in comment' => ["-- it's\nSELECT :x AS v, 'a' AS a", []],
		];
	}

	public function testNamesTheScriptOfAMissingParameter(): void
	{
		foreach (['inline.sql' => false, 'inline.tpql' => true] as $path => $isTemplate) {
			$script = new Script(
				new Database($this->connection()),
				new LoadedScript('SELECT :x AS v', $path),
				$isTemplate,
			);

			try {
				$script->invoke(['y' => 1]);
				$this->fail("Expected a MissingParameter for {$path}");
			} catch (MissingParameter $e) {
				$this->assertSame("Missing parameter :x in {$path}.", $e->getMessage());
				$this->assertSame($path, $e->script);
			}
		}
	}

	#[DataProvider('missingPositional')]
	public function testCountsMissingPositionalParameters(
		string $driver,
		string $sql,
		int $given,
		string $message,
	): void {
		$db = new Database($this->connection(dsn: $this->dsn($driver)));

		$this->expectException(MissingParameter::class);
		$this->expectExceptionMessage($message);

		$db->execute($sql, ...array_fill(0, $given, 1));
	}

	/** @return array<string, array{string, string, int, string}> */
	public static function missingPositional(): array
	{
		return [
			'sqlite none' => ['sqlite', 'SELECT ? AS v', 0, 'Expected 1 positional parameters, got 0.'],
			'mysql none' => ['mysql', 'SELECT ? AS v', 0, 'Expected 1 positional parameters, got 0.'],
			'sqlite some' => ['sqlite', 'SELECT ? AS v, ? AS w', 1, 'Expected 2 positional parameters, got 1.'],
			'escaped question mark' => [
				'sqlite',
				'SELECT ?? AS a, ? AS b',
				0,
				'Expected 1 positional parameters, got 0.',
			],
			'pgsql some' => ['pgsql', 'SELECT ? AS v, ? AS w', 1, 'Expected 2 positional parameters, got 1.'],
		];
	}

	/** @param list<mixed> $args */
	#[DataProvider('validStatements')]
	public function testAcceptsWhatOnlyLooksLikeAParameter(string $driver, string $sql, array $args): void
	{
		$db = new Database($this->connection(dsn: $this->dsn($driver)));

		$this->assertNotNull($db->execute($sql, ...$args)->first());
	}

	/** @return array<string, array{string, string, list<mixed>}> */
	public static function validStatements(): array
	{
		return [
			'name in string' => ['sqlite', "SELECT ':x' AS v", []],
			'name in comment' => ['sqlite', "SELECT 1 AS v -- :x\n", []],
			'question mark in string' => ['sqlite', "SELECT '?' AS v", []],
			'bound parameter' => ['sqlite', 'SELECT :x AS v', [['x' => null]]],
			'positional' => ['sqlite', 'SELECT ? AS v', [1]],
			'mysql statement without parameters' => ['mysql', 'SELECT 1 AS v', []],
			'pgsql cast' => ['pgsql', "SELECT '1'::int AS v", []],
			'pgsql slice' => ['pgsql', 'SELECT (ARRAY[1, 2, 3])[1:n] AS v FROM (SELECT 2 AS n) t', []],
			'pgsql slice in parentheses' => ['pgsql', 'SELECT (ARRAY[1, 2, 3])[(1):n] AS v FROM (SELECT 2 AS n) t', []],
			'pgsql slice without lower bound' => [
				'pgsql',
				'SELECT (ARRAY[1, 2, 3])[:n] AS v FROM (SELECT 2 AS n) t',
				[],
			],
			'pgsql question mark operator' => ['pgsql', "SELECT '{\"a\": 1}'::jsonb ? 'a' AS v", []],
			'pgsql named argument' => ['pgsql', 'SELECT make_interval(years := 1) AS v', []],
			'mysql assignment' => ['mysql', 'SELECT @quma_test := 1 AS v', []],
			'escaped question mark with values' => ['pgsql', "SELECT '{\"a\": 1}'::jsonb ?? ? AS v", ['a']],
		];
	}

	public function testLeavesAStatementItCannotReadToPdo(): void
	{
		$db = new Database($this->connection());
		$query = $this->withoutBacktracking(static fn() => $db->execute('/* comment */ SELECT 1 AS v'));

		$this->assertSame(['v' => 1], $query->one());
	}

	private function dsn(string $driver): string
	{
		foreach (self::getAvailableDsns() as $dsn) {
			if (str_starts_with($dsn, "{$driver}:")) {
				return $dsn;
			}
		}

		$this->markTestSkipped("{$driver} is not available.");
	}
}
