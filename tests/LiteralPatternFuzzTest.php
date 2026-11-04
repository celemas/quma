<?php

declare(strict_types=1);

namespace Celema\Quma\Tests;

use Celema\Quma\Database;
use Celema\Quma\LoadedScript;
use Celema\Quma\Script;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/**
 * The servers judge how Quma reads literals and comments: random SELECTs from
 * tricky pieces must return the row the pieces promise, with the value of :x
 * wherever it stands and :y, which only appears in text, never counted as a
 * parameter. Set QUMA_FUZZ_RUNS and QUMA_FUZZ_SEED to explore or to repeat a failure.
 *
 * @internal
 */
final class LiteralPatternFuzzTest extends TestCase
{
	private const string VALUE = "v'\"`\n*/ -- # $$ \\ :y ? [";
	private const int RUNS = 150;
	private const int SEED = 20261104;

	/** Each item is a selected expression and the value it has to return. */
	private const array ITEMS = [
		[':x', self::VALUE],
		["'plain'", 'plain'],
		["'it''s'", "it's"],
		["':y'", ':y'],
		["'?'", '?'],
		["'--'", '--'],
		["'/*'", '/*'],
		["'*/'", '*/'],
		["'$$'", '$$'],
		["'\"'", '"'],
		["'#'", '#'],
		["'`'", '`'],
		["'['", '['],
	];

	private const array COMMENTS = [
		"-- it's :y \" $$ /*\n",
		"--\n",
		"/* it's :y \" $$ -- */",
		'/**/',
		"/* ' */",
		"-- ` [\n",
	];

	/** Quoted aliases hide the same characters, and the servers ignore their names here. */
	private const array ALIASES = ['', ' AS "a:y"', ' AS "it\'s"', ' AS "a""b"', ' AS "$$"', ' AS "--"'];

	private const array DRIVER_ITEMS = [
		'mysql' => [
			["'it\\'s'", "it's"],
			["'\\\\'", '\\'],
			['"it\'s"', "it's"],
			['"a\\"b"', 'a"b'],
			['"a""b"', 'a"b'],
			['5--3', '8'],
			['5--:n', '8'],
		],
		'pgsql' => [
			["E'it\\'s'", "it's"],
			["e'a''b\\'c'", "a'b'c"],
			["E'\\\\'", '\\'],
			["'\\'", '\\'],
			['$$ :y $$', ' :y '],
			['$t$ :y $$ $t$', ' :y $$ '],
			['1 # 3', '2'],
			[':n::int', '3'],
		],
		'sqlite' => [
			["'\\'", '\\'],
		],
	];

	private const array DRIVER_COMMENTS = [
		'mysql' => ["# it's :y \" /*\n"],
		'pgsql' => ["-- \$t\$ it's\n"],
		'sqlite' => [],
	];

	private const array DRIVER_ALIASES = [
		'mysql' => [" AS `it's`", ' AS `:y`', ' AS `a``b`', ' AS `"`', ' AS a$$'],
		'pgsql' => [],
		'sqlite' => [" AS `it's`", ' AS `:y`', " AS [it's]", ' AS [:y]', ' AS [--]', ' AS a$$'],
	];

	/** @return array<string, array{string}> */
	public static function drivers(): array
	{
		return ['sqlite' => ['sqlite'], 'pgsql' => ['pgsql'], 'mysql' => ['mysql']];
	}

	#[DataProvider('drivers')]
	public function testServersAgreeWithQumasReading(string $driver): void
	{
		$db = $this->db($driver);
		$seed = (int) (getenv('QUMA_FUZZ_SEED') ?: self::SEED);
		$runs = (int) (getenv('QUMA_FUZZ_RUNS') ?: self::RUNS);
		mt_srand($seed);

		$items = [...self::ITEMS, ...self::DRIVER_ITEMS[$driver]];
		$comments = [...self::COMMENTS, ...self::DRIVER_COMMENTS[$driver]];
		$aliases = [...self::ALIASES, ...self::DRIVER_ALIASES[$driver]];

		for ($run = 0; $run < $runs; $run++) {
			[$sql, $expected] = $this->statement($items, $comments, $aliases);

			$this->assertRow($db, $sql, $expected, "{$driver}, seed {$seed}, run {$run}");
		}
	}

	/**
	 * @param list<array{string, string}> $items
	 * @param list<string> $comments
	 * @param list<string> $aliases
	 * @return array{string, list<string>}
	 */
	private function statement(array $items, array $comments, array $aliases): array
	{
		$columns = [];
		$expected = [];
		$count = mt_rand(1, 6);

		for ($i = 0; $i < $count; $i++) {
			[$sql, $value] = $items[mt_rand(0, count($items) - 1)];
			$columns[] =
				$this->comment($comments)
				. $sql
				. $this->comment($comments)
				. $aliases[mt_rand(0, count($aliases) - 1)];
			$expected[] = $value;
		}

		return [$this->comment($comments) . 'SELECT ' . implode(', ', $columns) . $this->comment($comments), $expected];
	}

	/** @param list<string> $comments */
	private function comment(array $comments): string
	{
		return mt_rand(0, 2) === 0 ? ' ' . $comments[mt_rand(0, count($comments) - 1)] . ' ' : ' ';
	}

	/** @param list<string> $expected */
	private function assertRow(Database $db, string $sql, array $expected, string $context): void
	{
		$args = array_filter(
			['x' => self::VALUE, 'y' => 'unused', 'n' => 3],
			static fn(string $name): bool => $name === 'y' || preg_match("/(?<!:):{$name}\\b/", $sql) === 1,
			ARRAY_FILTER_USE_KEY,
		);
		$script = new Script($db, new LoadedScript($sql, 'fuzz.tpql'), true);

		try {
			$row = $script->invoke($args)->one(fetchMode: PDO::FETCH_NUM);
		} catch (Throwable $e) {
			$this->fail("{$context}: {$sql}\n" . $e::class . ': ' . $e->getMessage());
		}

		$this->assertSame($expected, array_map(strval(...), $row), "{$context}: {$sql}");
	}

	private function db(string $driver): Database
	{
		foreach (self::getAvailableDsns() as $dsn) {
			if (str_starts_with($dsn, "{$driver}:")) {
				return new Database($this->connection(dsn: $dsn));
			}
		}

		$this->markTestSkipped("{$driver} is not available.");
	}
}
