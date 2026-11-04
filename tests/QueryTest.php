<?php

declare(strict_types=1);

namespace Celema\Quma\Tests;

use Celema\Quma\Connection;
use Celema\Quma\Database;
use Celema\Quma\Exception\UnexpectedResultCount;
use Celema\Quma\Query;
use Closure;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
final class QueryTest extends TestCase
{
	private string $file;

	protected function setUp(): void
	{
		parent::setUp();
		$this->file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quma-query-' . uniqid() . '.sqlite3';
		$pdo = new PDO('sqlite:' . $this->file);
		$pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY)');
		$pdo->exec('INSERT INTO items (id) VALUES (1), (2)');
	}

	protected function tearDown(): void
	{
		if (is_file($this->file)) {
			unlink($this->file);
		}

		parent::tearDown();
	}

	public function testLenExecutesAQueryThatHasNotRun(): void
	{
		$db = $this->db();

		$this->assertSame(1, $db->execute('INSERT INTO items (id) VALUES (3)')->len());
		$this->assertSame(3, $this->countItems($db));
	}

	public function testLenCountsTheRunOfAQueryThatRan(): void
	{
		$db = $this->db();
		$query = $db->execute('INSERT INTO items (id) VALUES (3)');
		$query->run();

		$this->assertSame(1, $query->len());
		$this->assertSame(1, $query->len());
		$this->assertSame(3, $this->countItems($db));
	}

	public function testLenDoesNotRepeatAQueryThatReturnedRows(): void
	{
		$db = $this->db();
		$query = $db->execute('INSERT INTO items (id) VALUES (3) RETURNING id');
		$query->all();
		$query->len();

		$this->assertSame(3, $this->countItems($db));
	}

	public function testLenLeavesAnOpenFetchUntouched(): void
	{
		$query = $this->db()->execute('SELECT id FROM items ORDER BY id');
		$first = $query->fetch();
		$query->len();

		$this->assertSame(['id' => 1], $first);
		$this->assertSame(['id' => 2], $query->fetch());
	}

	public function testRunRepeatsAQueryAndLenCountsTheLatestRun(): void
	{
		$db = $this->db();
		$query = $db->execute('DELETE FROM items WHERE id = 1');
		$this->assertSame(1, $query->len());

		$query->run();

		$this->assertSame(0, $query->len());
	}

	private function countItems(Database $db): int
	{
		$record = $db->execute('SELECT count(*) AS n FROM items')->one();

		return (int) $record['n'];
	}

	/** @param Closure(Query): mixed $read */
	#[DataProvider('readProvider')]
	public function testReadingReleasesTheTableForOtherConnections(Closure $read): void
	{
		$reader = $this->db();
		$writer = $this->db(busyTimeout: 0);

		// The query stays referenced: destroying it would release the table anyway.
		$query = $reader->execute('SELECT id FROM items ORDER BY id');
		$read($query);

		$this->assertTrue($writer->execute('INSERT INTO items (id) VALUES (3)')->run());
		$this->assertInstanceOf(Query::class, $query);
	}

	/** @return array<string, array{Closure(Query): mixed}> */
	public static function readProvider(): array
	{
		return [
			'first row' => [static fn(Query $query): mixed => $query->first()],
			'one row' => [
				static function (Query $query): void {
					try {
						$query->one();
					} catch (UnexpectedResultCount) {
						// @mago-expect lint:no-empty-catch-clause Two rows: the failure must release the table as well.
					}
				},
			],
			'all rows' => [static fn(Query $query): array => $query->all()],
			'lazy rows' => [
				static fn(Query $query): mixed => $query->lazy()->current(),
			],
			'row count' => [static fn(Query $query): int => $query->len()],
		];
	}

	public function testLenExecutesTheStatement(): void
	{
		$db = $this->db();

		$this->assertSame(2, $db->execute('UPDATE items SET id = id')->len());
	}

	public function testFetchAfterLenStartsFromTheFirstRow(): void
	{
		$query = $this->db()->execute('SELECT id FROM items ORDER BY id');
		$query->len();

		$this->assertSame(['id' => 1], $query->fetch(fetchMode: PDO::FETCH_ASSOC));
	}

	public function testBindsBooleanNullAndArrayValues(): void
	{
		$row = $this
			->db()
			->execute('SELECT ? AS flag, ? AS empty, ? AS list', true, null, [1, 2])
			->one(fetchMode: PDO::FETCH_ASSOC);

		$this->assertSame(['flag' => 1, 'empty' => null, 'list' => '[1,2]'], $row);
	}

	private function db(?int $busyTimeout = null): Database
	{
		$conn = new Connection('sqlite:' . $this->file, self::root() . 'sql/default');

		if ($busyTimeout !== null) {
			$conn->option(PDO::ATTR_TIMEOUT, $busyTimeout);
		}

		return new Database($conn);
	}
}
