<?php

declare(strict_types=1);

namespace Celema\Quma\Tests;

use Celema\Quma\Database;
use Celema\Quma\Exception\QueryFailed;
use Celema\Quma\LiteralPattern;
use Celema\Quma\LoadedScript;
use Celema\Quma\Script;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
final class ListParameterTest extends TestCase
{
	private const string NUMBERS = 'WITH RECURSIVE c(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM c WHERE n < 5) SELECT n FROM c WHERE ';

	/**
	 * @param array<string, mixed> $args
	 * @param list<int> $expected
	 */
	#[DataProvider('lists')]
	public function testExpandsAListAfterIn(string $condition, array $args, array $expected): void
	{
		foreach (self::getAvailableDsns() as $dsn) {
			$db = new Database($this->connection(dsn: $dsn));

			$rows = $db->execute(self::NUMBERS . $condition . ' ORDER BY n', $args)->all(fetchMode: PDO::FETCH_NUM);

			$this->assertSame($expected, array_map(static fn(array $row): int => (int) $row[0], $rows), $dsn);
		}
	}

	/** @return array<string, array{string, array<string, mixed>, list<int>}> */
	public static function lists(): array
	{
		return [
			'in' => ['n IN :ids', ['ids' => [2, 4]], [2, 4]],
			'not in' => ['n NOT IN :ids', ['ids' => [2, 4]], [1, 3, 5]],
			'lower case' => ['n in :ids', ['ids' => [2, 4]], [2, 4]],
			'without whitespace' => ['n IN:ids', ['ids' => [2, 4]], [2, 4]],
			'with a line break' => ["n IN\n\t:ids", ['ids' => [2, 4]], [2, 4]],
			'one element' => ['n IN :ids', ['ids' => [3]], [3]],
			'more than ten elements' => ['n IN :ids', ['ids' => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 5]], [5]],
			'keys are ignored' => ['n IN :ids', ['ids' => ['a' => 1, 'b' => 2]], [1, 2]],
			'two lists' => ['n IN :a OR n IN :b', ['a' => [1], 'b' => [2]], [1, 2]],
			'with other parameters' => ['n IN :ids AND n > :min', ['ids' => [1, 2, 3], 'min' => 2], [3]],
		];
	}

	public function testBindsEachElementByItsType(): void
	{
		$db = new Database($this->connection());

		$row = $db->execute("SELECT 'it''s' IN :a AS quoted, 1 IN :b AS number, 1 IN :c AS none", [
			'a' => ["it's", "a' OR '1'='1"],
			'b' => [1],
			'c' => [null],
		])->one();

		$this->assertSame(['quoted' => 1, 'number' => 1, 'none' => null], $row);
	}

	public function testExpandsAListInATemplate(): void
	{
		$script = new Script(
			new Database($this->connection()),
			new LoadedScript('SELECT 2 IN :ids AS found', 'inline.tpql'),
			true,
		);

		$this->assertSame(['found' => 1], $script->invoke(['ids' => [1, 2], 'unused' => [3]])->one());
	}

	public function testKeepsAListThatIsAlsoUsedAsJson(): void
	{
		$row = new Database($this->connection())
			->execute('SELECT 2 IN :ids AS found, :ids AS json', ['ids' => [1, 2]])
			->one();

		$this->assertSame(['found' => 1, 'json' => '[1,2]'], $row);
	}

	#[DataProvider('json')]
	public function testLeavesOtherArraysAsJson(string $sql, string $expected): void
	{
		$query = new Database($this->connection())->execute($sql, ['ids' => [1, 2]]);

		$this->assertSame($expected, $query->interpolate());
	}

	/** @return array<string, array{string, string}> */
	public static function json(): array
	{
		return [
			'parentheses' => ['SELECT 1 IN (:ids)', "SELECT 1 IN ('[1,2]')"],
			'other operator' => ['SELECT 1 = :ids', "SELECT 1 = '[1,2]'"],
			'word ending in in' => ['SELECT 1 AS margin, :ids', "SELECT 1 AS margin, '[1,2]'"],
			'in a string' => ["SELECT 'IN :ids', :ids", "SELECT 'IN :ids', '[1,2]'"],
			'in a comment' => ["SELECT :ids -- IN :ids\n", "SELECT '[1,2]' -- IN :ids\n"],
		];
	}

	public function testLeavesACastListAsJsonOnPostgres(): void
	{
		$dsns = array_filter(self::getAvailableDsns(), static fn(string $dsn): bool => str_starts_with($dsn, 'pgsql:'));

		if ($dsns === []) {
			$this->markTestSkipped('The cast requires PostgreSQL.');
		}

		$query = new Database($this->connection(dsn: array_first($dsns)))
			->execute('SELECT 1 = ANY(SELECT jsonb_array_elements_text(:ids::jsonb)::int) AS found', ['ids' => [1, 2]]);

		$this->assertTrue($query->one()['found']);
	}

	public function testLeavesAScalarAfterInAlone(): void
	{
		$this->expectException(QueryFailed::class);
		$this->expectExceptionMessage('near ":ids"');

		new Database($this->connection())->execute('SELECT 1 IN :ids', ['ids' => 1]);
	}

	#[DataProvider('notLists')]
	public function testDoesNotReadOtherTextAsAList(string $sql): void
	{
		preg_match(LiteralPattern::withLists('sqlite'), $sql, $matches);

		$this->assertArrayNotHasKey('list', $matches);
	}

	/** @return array<string, array{string}> */
	public static function notLists(): array
	{
		return [
			'qualified column' => ['t.in :ids'],
			'end of a word' => ['margin :ids'],
			'end of a dollar name' => ['a$in :ids'],
			'cast' => ['IN :ids::jsonb'],
			'no parameter' => ['IN (SELECT 1)'],
		];
	}

	public function testRefusesAnEmptyList(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The list :ids after IN must not be empty.');

		new Database($this->connection())->execute('SELECT 1 IN :ids', ['ids' => []]);
	}

	public function testRefusesACollidingParameter(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The parameter :ids__0 collides with the list :ids.');

		new Database($this->connection())->execute('SELECT 1 IN :ids, :ids__0', ['ids' => [1], 'ids__0' => 0]);
	}

	public function testInterpolatesTheExpandedList(): void
	{
		$query = new Database($this->connection())->execute('SELECT 1 IN :ids', ['ids' => [1, 'a']]);

		$this->assertSame("SELECT 1 IN (1, 'a')", $query->interpolate());
	}

	public function testRunsAgainAfterTheExpansion(): void
	{
		$query = new Database($this->connection())->execute('SELECT 1 IN :ids AS found', ['ids' => [1]]);

		$this->assertSame(['found' => 1], $query->one());
		$this->assertSame(['found' => 1], $query->one());
		$this->assertSame([['found' => 1]], iterator_to_array($query->stream()));
	}

	public function testLeavesAStatementItCannotReadUnexpanded(): void
	{
		$db = new Database($this->connection());

		$query = $this->withoutBacktracking(static fn() => $db->execute('SELECT 1 AS v /* a */, :ids AS j', ['ids' => [
			1,
		]]));

		$this->assertSame(['v' => 1, 'j' => '[1]'], $query->one());
	}
}
