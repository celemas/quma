<?php

declare(strict_types=1);

namespace Celema\Quma\Tests;

use Celema\Quma\Database;
use Celema\Quma\Exception\QueryFailed;
use Celema\Quma\Exception\StreamOpen;
use Celema\Quma\Tests\Util\InspectableDatabase;
use Celema\Quma\Tests\Util\StreamedNumber;
use Generator;
use Pdo\Mysql;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhp;
use RuntimeException;

/**
 * @internal
 */
final class StreamTest extends TestCase
{
	private const string NUMBERS = 'WITH RECURSIVE c(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM c WHERE n < 5) SELECT n FROM c';

	/** @return array<string, array{string}> */
	public static function drivers(): array
	{
		return ['sqlite' => ['sqlite'], 'pgsql' => ['pgsql'], 'mysql' => ['mysql']];
	}

	#[DataProvider('drivers')]
	public function testYieldsAllRows(string $driver): void
	{
		$db = $this->db($driver);

		$this->assertSame([1, 2, 3, 4, 5], $this->numbers($db->execute(self::NUMBERS)->stream()));
	}

	#[DataProvider('drivers')]
	public function testBindsParameters(string $driver): void
	{
		$db = $this->db($driver);
		$named = $db->execute('SELECT n FROM (' . self::NUMBERS . ') t WHERE n > :min', ['min' => 3]);
		$positional = $db->execute('SELECT n FROM (' . self::NUMBERS . ') t WHERE n > ?', 4);

		$this->assertSame([4, 5], $this->numbers($named->stream()));
		$this->assertSame([5], $this->numbers($positional->stream()));
	}

	#[DataProvider('drivers')]
	public function testStatementsAreRefusedWhileAStreamIsOpen(string $driver): void
	{
		$db = $this->db($driver);
		$prepared = $db->execute('SELECT 1 AS v');
		$stream = $db->execute(self::NUMBERS)->stream();
		$stream->current();

		foreach ([
			'new query' => static fn() => $db->execute('SELECT 2 AS v'),
			'prepared query' => $prepared->all(...),
			'second stream' => static fn() => $db->execute(self::NUMBERS),
			'begin' => $db->begin(...),
			'transaction' => static fn() => $db->transaction(static fn(): int => 1),
			'ping' => $db->ping(...),
		] as $label => $call) {
			try {
				$call();
				$this->fail("Expected {$label} to be refused.");
			} catch (StreamOpen $e) {
				$this->assertSame(
					'Another statement cannot run while a stream is open on the connection. '
						. 'End the loop first, or use lazy() or a second Database.',
					$e->getMessage(),
				);
			}
		}

		$stream->next();
		$this->assertSame(2, (int) $stream->current()['n']);
	}

	#[DataProvider('drivers')]
	public function testSecondStreamOfAPreparedQueryIsRefused(string $driver): void
	{
		$db = $this->db($driver);
		$query = $db->execute(self::NUMBERS);
		$stream = $query->stream();
		$stream->current();

		$this->expectException(StreamOpen::class);

		$query->stream()->current();
	}

	#[DataProvider('drivers')]
	public function testStatementsWorkAfterTheLastRow(string $driver): void
	{
		$db = $this->db($driver);
		$this->numbers($db->execute(self::NUMBERS)->stream());

		$this->assertSame(['v' => 1], $this->row($db->execute('SELECT 1 AS v')->one()));
	}

	#[DataProvider('drivers')]
	public function testBreakingOutOfTheLoopEndsTheStream(string $driver): void
	{
		$db = $this->db($driver);

		// @mago-expect lint:loop-does-not-iterate Leaving a stream's loop early is the behavior under test.
		foreach ($db->execute(self::NUMBERS)->stream() as $row) {
			break;
		}

		unset($row);

		$this->assertSame(['v' => 1], $this->row($db->execute('SELECT 1 AS v')->one()));
	}

	#[DataProvider('drivers')]
	public function testExceptionInTheLoopEndsTheStream(string $driver): void
	{
		$db = $this->db($driver);

		try {
			$this->throwInLoop($db);
			$this->fail('Expected the exception of the loop.');
		} catch (RuntimeException $e) {
			$this->assertSame('in the loop', $e->getMessage());
		}

		$this->assertSame(['v' => 1], $this->row($db->execute('SELECT 1 AS v')->one()));
	}

	#[DataProvider('drivers')]
	public function testAStreamThatWasNotStartedHoldsNothing(string $driver): void
	{
		$db = $this->db($driver);
		$stream = $db->execute(self::NUMBERS)->stream();

		$this->assertSame(['v' => 1], $this->row($db->execute('SELECT 1 AS v')->one()));
		$this->assertSame([1, 2, 3, 4, 5], $this->numbers($stream));
	}

	#[DataProvider('drivers')]
	public function testALaterQueryRunsAfterAStreamOfTheSameQuery(string $driver): void
	{
		$db = $this->db($driver);
		$query = $db->execute(self::NUMBERS);
		$this->numbers($query->stream());

		$this->assertSame(5, $query->len());
		$this->assertCount(5, $query->all());
		$this->assertCount(5, iterator_to_array($query->lazy()));
		$this->assertSame([1, 2, 3, 4, 5], $this->numbers($query->stream()));
	}

	#[DataProvider('drivers')]
	public function testResetEndsAStream(string $driver): void
	{
		$db = $this->db($driver);
		$stream = $db->execute(self::NUMBERS)->stream();
		$stream->current();

		$this->assertTrue($db->reset());
		$this->assertSame(['v' => 1], $this->row($db->execute('SELECT 1 AS v')->one()));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The stream ended when its connection was reset or closed.');

		$stream->next();
	}

	#[DataProvider('drivers')]
	public function testDisconnectEndsAStream(string $driver): void
	{
		$db = $this->db($driver);
		$stream = $db->execute(self::NUMBERS)->stream();
		$stream->current();

		$db->disconnect();

		$this->assertSame(['v' => 1], $this->row($db->execute('SELECT 1 AS v')->one()));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The stream ended when its connection was reset or closed.');

		$stream->next();
	}

	#[DataProvider('drivers')]
	public function testFailedStatementThrowsAQueryFailure(string $driver): void
	{
		$db = $this->db($driver);

		try {
			$db->execute('SELECT * FROM quma_missing_table')->stream()->current();
			$this->fail('Expected the statement to fail.');
		} catch (QueryFailed $e) {
			$this->assertNotEmpty($e->getMessage());
		}

		$this->assertSame(['v' => 1], $this->row($db->execute('SELECT 1 AS v')->one()));
	}

	public function testClosingAStreamSurvivesAFailingDriver(): void
	{
		$db = new InspectableDatabase($this->connection());
		$db->getConn();
		$stmt = new class extends PDOStatement {
			public function __construct() {}

			public function closeCursor(): bool
			{
				throw new PDOException('connection lost');
			}
		};
		$db->openStream($stmt);

		$db->closeStream();

		$this->assertFalse($db->streaming($stmt));
		$this->assertTrue($db->suspectPublic());
		$this->assertSame(['v' => 1], $this->row($db->execute('SELECT 1 AS v')->one()));
	}

	public function testFailureWhileFetchingThrowsAQueryFailure(): void
	{
		$db = $this->db('pgsql');
		$stream = $db->execute('SELECT 1 / (2 - g) AS n FROM generate_series(1, 3) g')->stream();

		$this->expectException(QueryFailed::class);
		$this->expectExceptionMessage('division by zero');

		iterator_to_array($stream);
	}

	public function testHydratesRows(): void
	{
		$db = $this->db('sqlite');

		$rows = iterator_to_array($db->execute(self::NUMBERS)->stream(StreamedNumber::class), false);

		$this->assertContainsOnlyInstancesOf(StreamedNumber::class, $rows);
		$this->assertSame(5, $rows[4]->n);
	}

	public function testRefusesAnInvalidFetchMode(): void
	{
		$this->expectExceptionMessage('Hydration requires PDO::FETCH_ASSOC.');

		$this
			->db('sqlite')
			->execute(self::NUMBERS)
			->stream(StreamedNumber::class, \PDO::FETCH_NUM)
			->current();
	}

	public function testFailureAfterTheFirstRowsThrowsAQueryFailure(): void
	{
		$db = $this->db('mysql');
		$stream = $db
			->execute(
				'SELECT n, IF(n = 3, (SELECT 1 UNION SELECT 2), 1) AS v FROM (' . self::NUMBERS . ') t',
			)
			->stream();
		$rows = [];

		try {
			foreach ($stream as $row) {
				$rows[] = (int) $row['n'];
			}

			$this->fail('Expected the third row to fail.');
		} catch (QueryFailed $e) {
			$this->assertStringContainsString('more than 1 row', $e->getMessage());
		}

		$this->assertSame([1, 2], $rows);
	}

	#[RequiresPhp('>=8.5.10')]
	public function testPostgresDoesNotBufferTheResult(): void
	{
		$db = $this->db('pgsql');
		$stream = $db->execute(self::NUMBERS)->stream();
		$stream->current();

		// Only an unbuffered result ends when another statement runs.
		$db->getConn()->query('SELECT 1')->fetchAll();
		$stream->next();

		$this->assertNull($stream->current());
	}

	public function testMysqlDoesNotBufferTheResultButKeepsItsDefault(): void
	{
		$db = $this->db('mysql');
		$stream = $db->execute(self::NUMBERS)->stream();
		$stream->current();

		$this->assertTrue((bool) $db->getConn()->getAttribute(Mysql::ATTR_USE_BUFFERED_QUERY));

		$this->expectException(PDOException::class);
		$this->expectExceptionMessage('unbuffered');

		$db->getConn()->query('SELECT 1')->fetchAll();
	}

	private function throwInLoop(Database $db): void
	{
		foreach ($db->execute(self::NUMBERS)->stream() as $row) {
			throw new RuntimeException('in the loop');
		}
	}

	/**
	 * @param Generator<array-key, array<array-key, mixed>, mixed, void> $rows
	 * @return list<int>
	 */
	private function numbers(Generator $rows): array
	{
		$numbers = [];

		foreach ($rows as $row) {
			$numbers[] = (int) $row['n'];
		}

		return $numbers;
	}

	/**
	 * @param array<array-key, mixed> $row
	 * @return array<string, int>
	 */
	private function row(array $row): array
	{
		return ['v' => (int) $row['v']];
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
