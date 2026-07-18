<?php

declare(strict_types=1);

namespace Celema\Quma\Tests;

use Celema\Quma\Database;
use Celema\Quma\Exception\UnexpectedResultCount;
use InvalidArgumentException;
use PDO;

/**
 * @internal
 */
class QueryHydrationTest extends TestCase
{
	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
		self::createTestDb();
	}

	public function testAllOneFirstAndLazyHydrateRows(): void
	{
		$db = $this->getDb();

		$members = $db->members->list()->all(QueryHydrationMember::class);
		$one = $db
			->execute('SELECT member, name, joined, left FROM members WHERE member = 1')
			->one(QueryHydrationMember::class);
		$first = $db->members->list()->first(QueryHydrationMember::class);
		$firstLazy = $db->members->list()->lazy(QueryHydrationMember::class)->current();

		$this->assertCount(DatabaseTest::NUMBER_OF_MEMBERS, $members);
		$this->assertInstanceOf(QueryHydrationMember::class, $members[0]);
		$this->assertInstanceOf(QueryHydrationMember::class, $one);
		$this->assertInstanceOf(QueryHydrationMember::class, $first);
		$this->assertInstanceOf(QueryHydrationMember::class, $firstLazy);
	}

	public function testOneThrowsWhenNoRowExists(): void
	{
		$this->expectException(UnexpectedResultCount::class);

		$this
			->getDb()
			->execute('SELECT member, name, joined, left FROM members WHERE member = -1')
			->one(QueryHydrationMember::class);
	}

	public function testFirstReturnsNullWhenNoRowExists(): void
	{
		$result = $this
			->getDb()
			->execute('SELECT member, name, joined, left FROM members WHERE member = -1')
			->first(QueryHydrationMember::class);

		$this->assertNull($result);
	}

	public function testUnmappedQueriesDefaultToAssociativeRows(): void
	{
		$row = $this->getDb()->members->byId(1)->one();

		$this->assertIsArray($row);
		$this->assertArrayHasKey('name', $row);
		$this->assertArrayNotHasKey(0, $row);
	}

	public function testFetchModeOverridesUseSecondOrNamedArgument(): void
	{
		$db = $this->getDb();

		$one = $db->members->byId(1)->one(fetchMode: PDO::FETCH_NUM);
		$all = $db->members->byId(1)->all(null, PDO::FETCH_NUM);

		$this->assertSame('Chuck Schuldiner', $one[1]);
		$this->assertSame('Chuck Schuldiner', $all[0][1]);
		$this->assertArrayNotHasKey('name', $one);
	}

	public function testMappedCallsFetchAssociativeRowsRegardlessOfConnectionDefault(): void
	{
		$conn = $this->connection()->fetch(PDO::FETCH_NUM);
		$db = new Database($conn);

		$member = $db->members->list()->first(QueryHydrationMember::class);

		$this->assertInstanceOf(QueryHydrationMember::class, $member);
		$this->assertSame('Chuck Schuldiner', $member->name);
	}

	public function testMappedCallsRejectNonAssociativeFetchMode(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Hydration requires PDO::FETCH_ASSOC');

		$this->getDb()->members->list()->all(QueryHydrationMember::class, PDO::FETCH_BOTH);
	}

	public function testClosureResolverRunsPerHydratedRow(): void
	{
		$calls = 0;
		$resolver = static function (array $row) use (&$calls): string {
			$calls++;

			return $row['type'] === 'created'
				? QueryHydrationCreatedEvent::class
				: QueryHydrationDeletedEvent::class;
		};

		$events = $this
			->getDb()
			->execute(
				"SELECT 'created' AS type, 1 AS id UNION ALL SELECT 'deleted' AS type, 2 AS id",
			)
			->all($resolver);

		$this->assertSame(2, $calls);
		$this->assertInstanceOf(QueryHydrationCreatedEvent::class, $events[0]);
		$this->assertInstanceOf(QueryHydrationDeletedEvent::class, $events[1]);
	}

	public function testLazyHydratesOnlyYieldedRows(): void
	{
		$calls = 0;
		$resolver = static function (array $row) use (&$calls): string {
			$calls++;

			return QueryHydrationMember::class;
		};

		$member = $this->getDb()->members->list()->lazy($resolver)->current();

		$this->assertInstanceOf(QueryHydrationMember::class, $member);
		$this->assertSame(1, $calls);
	}

	public function testFetchHydratesSuccessiveRowsAndStopsAtCursorEnd(): void
	{
		$query = $this
			->getDb()
			->members->activeFromTo([
				'from' => 1990,
				'to' => 1995,
			]);
		$calls = 0;
		$resolver = static function (array $row) use (&$calls): string {
			$calls++;

			return QueryHydrationMember::class;
		};

		$count = 0;

		while ($query->fetch($resolver) instanceof QueryHydrationMember) {
			$count++;
		}

		$this->assertSame(7, $count);
		$this->assertSame(7, $calls);
		$this->assertNull($query->fetch($resolver));
		$this->assertSame(7, $calls);
	}

	public function testScriptHydrationIncludesSourcePath(): void
	{
		$this->expectExceptionMessage(TestCase::root() . 'sql/default/members/byId.sql');
		$this->expectExceptionMessage("missing required column 'joined'");

		$this->getDb()->members->byId(1)->one(QueryHydrationMember::class);
	}

	public function testAdHocHydrationMentionsAdHocSql(): void
	{
		$this->expectExceptionMessage('from ad-hoc SQL');
		$this->expectExceptionMessage("missing required column 'name'");

		$this->getDb()->execute('SELECT 1 AS member')->one(QueryHydrationMember::class);
	}
}

final readonly class QueryHydrationMember
{
	public function __construct(
		public int $member,
		public string $name,
		public int $joined,
		public ?int $left,
	) {}
}

abstract class QueryHydrationEvent
{
	public function __construct(
		public int $id,
	) {}
}

final class QueryHydrationCreatedEvent extends QueryHydrationEvent {}

final class QueryHydrationDeletedEvent extends QueryHydrationEvent {}
