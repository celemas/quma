<?php

declare(strict_types=1);

namespace Celema\Quma\Tests;

use Celema\Quma\Database;
use LogicException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
final class AfterCommitTest extends TestCase
{
	/** @var list<string> */
	private array $calls = [];

	/** @return array<string, array{string}> */
	public static function drivers(): array
	{
		return ['sqlite' => ['sqlite'], 'pgsql' => ['pgsql'], 'mysql' => ['mysql']];
	}

	public function testRunsAtOnceOutsideATransaction(): void
	{
		$db = new Database($this->connection());

		$db->afterCommit($this->record('unconnected'));
		$this->assertFalse($db->connected());

		$db->execute('SELECT 1')->run();
		$db->afterCommit($this->record('connected'));

		$this->assertSame(['unconnected', 'connected'], $this->calls);
	}

	#[DataProvider('drivers')]
	public function testRunsInOrderOnceTheBlockCommitted(string $driver): void
	{
		$db = $this->db($driver);

		$db->transaction(function () use ($db): void {
			$this->add($db, 1);
			$db->afterCommit(function () use ($db): void {
				$this->calls[] = $db->getConn()->inTransaction() ? 'inside' : 'committed';
			});
			$db->afterCommit($this->record('second'));

			$this->assertSame([], $this->calls);
		});

		$this->assertSame(['committed', 'second'], $this->calls);
		$this->assertSame([1], $this->rows($db));
	}

	#[DataProvider('drivers')]
	public function testDiscardsTheCallbacksOfAFailedBlock(string $driver): void
	{
		$db = $this->db($driver);

		try {
			$db->transaction(function () use ($db): void {
				$db->afterCommit($this->record('failed'));

				throw new LogicException('failed');
			});
		} catch (LogicException) {
			// @mago-expect lint:no-empty-catch-clause The block failed on purpose.
		}

		$db->transaction(fn() => $this->add($db, 1));

		$this->assertSame([], $this->calls);
	}

	#[DataProvider('drivers')]
	public function testANestedBlockHandsItsCallbacksToTheOuterTransaction(string $driver): void
	{
		$db = $this->db($driver);

		$db->transaction(function () use ($db): void {
			$db->afterCommit($this->record('outer'));
			$db->transaction(function () use ($db): void {
				$db->afterCommit($this->record('inner'));
			});

			$this->assertSame([], $this->calls, 'Releasing a savepoint commits nothing');
		});

		$this->assertSame(['outer', 'inner'], $this->calls);
	}

	#[DataProvider('drivers')]
	public function testAFailedNestedBlockDiscardsOnlyItsOwnCallbacks(string $driver): void
	{
		$db = $this->db($driver);

		$db->transaction(function () use ($db): void {
			$db->afterCommit($this->record('outer'));
			$db->transaction(function () use ($db): void {
				$db->afterCommit($this->record('middle'));

				try {
					$db->transaction(function () use ($db): void {
						$db->afterCommit($this->record('inner'));

						throw new LogicException('failed');
					});
				} catch (LogicException) {
					// @mago-expect lint:no-empty-catch-clause The middle block goes on.
				}

				$db->afterCommit($this->record('after inner'));
			});

			try {
				$db->transaction(static function (): void {
					throw new LogicException('failed');
				});
			} catch (LogicException) {
				// @mago-expect lint:no-empty-catch-clause A failing sibling must not discard the middle block's callbacks.
			}
		});

		$this->assertSame(['outer', 'middle', 'after inner'], $this->calls);
	}

	#[DataProvider('drivers')]
	public function testCommitAndRollbackDecideAboutABegunTransaction(string $driver): void
	{
		$db = $this->db($driver);

		$db->begin();
		$db->afterCommit($this->record('rolled back'));
		$db->rollback();
		$this->assertSame([], $this->calls);

		$db->begin();
		$db->afterCommit($this->record('committed'));

		try {
			$db->transaction(function () use ($db): void {
				$db->afterCommit($this->record('failed block'));

				throw new LogicException('failed');
			});
		} catch (LogicException) {
			// @mago-expect lint:no-empty-catch-clause The begun transaction goes on.
		}

		$this->assertSame([], $this->calls);
		$this->assertTrue($db->commit());
		$this->assertSame(['committed'], $this->calls);
	}

	#[DataProvider('drivers')]
	public function testAFailingCallbackKeepsTheCommitAndSkipsTheRest(string $driver): void
	{
		$db = $this->db($driver);
		$failure = new LogicException('Mail server unavailable');

		try {
			$db->transaction(function () use ($db, $failure): void {
				$this->add($db, 1);
				$db->afterCommit(static function () use ($failure): void {
					throw $failure;
				});
				$db->afterCommit($this->record('skipped'));
			});
			$this->fail('Expected the exception of the callback.');
		} catch (LogicException $e) {
			$this->assertSame($failure, $e);
		}

		$this->assertFalse($db->getConn()->inTransaction());
		$this->assertSame([1], $this->rows($db));

		$db->transaction(fn() => $this->add($db, 2));
		$this->assertSame([], $this->calls);
	}

	public function testRunsWhenAStatementCommittedImplicitlyOnMysql(): void
	{
		$db = $this->db('mysql');

		try {
			$db->transaction(function () use ($db): void {
				$db->afterCommit($this->record('implicit'));
				$db->execute('CREATE TABLE quma_implicit_commit (id INT)')->run();
			});
		} finally {
			$db->execute('DROP TABLE IF EXISTS quma_implicit_commit')->run();
		}

		$this->assertSame(['implicit'], $this->calls);
	}

	/**
	 * Callbacks of a transaction that did not end through the database must
	 * not run at the commit of another one.
	 */
	public function testStaleCallbacksAreDiscarded(): void
	{
		$db = $this->db('sqlite');
		$pdo = $db->getConn();

		$pdo->beginTransaction();
		$db->afterCommit($this->record('committed on PDO'));
		$pdo->commit();
		$db->begin();
		$db->commit();

		$db->begin();
		$db->afterCommit($this->record('rolled back'));
		$db->rollback();
		$pdo->beginTransaction();
		$db->commit();

		$pdo->beginTransaction();
		$db->afterCommit($this->record('reset'));
		$db->reset();
		$pdo->beginTransaction();
		$db->commit();

		$this->assertSame([], $this->calls);
	}

	public function testAFailedCommitDiscardsTheCallbacks(): void
	{
		$db = $this->db('pgsql');
		$db
			->execute('CREATE TEMPORARY TABLE quma_deferred (id INT UNIQUE DEFERRABLE INITIALLY DEFERRED)')
			->run();

		$db->begin();
		$db->execute('INSERT INTO quma_deferred VALUES (1), (1)')->run();
		$db->afterCommit($this->record('failed commit'));

		try {
			$db->commit();
			$this->fail('Expected the commit to fail.');
		} catch (PDOException) {
			$this->addToAssertionCount(1);
		}

		$db->getConn()->beginTransaction();
		$db->commit();

		$this->assertSame([], $this->calls);
	}

	public function testDisconnectDiscardsTheCallbacks(): void
	{
		$db = $this->db('sqlite');

		$db->begin();
		$db->afterCommit($this->record('disconnected'));
		$db->disconnect();
		$db->getConn()->beginTransaction();
		$db->commit();

		$this->assertSame([], $this->calls);
	}

	/** @return \Closure(): void */
	private function record(string $name): \Closure
	{
		return function () use ($name): void {
			$this->calls[] = $name;
		};
	}

	private function db(string $driver): Database
	{
		foreach (self::getAvailableDsns() as $dsn) {
			if (!str_starts_with($dsn, "{$driver}:")) {
				continue;
			}

			$db = new Database($this->connection(dsn: $dsn));
			$db->execute('CREATE TEMPORARY TABLE quma_after_commit (id INT)')->run();

			return $db;
		}

		$this->markTestSkipped("{$driver} is not available.");
	}

	private function add(Database $db, int $id): void
	{
		$db->execute('INSERT INTO quma_after_commit (id) VALUES (?)', $id)->run();
	}

	/** @return list<int> */
	private function rows(Database $db): array
	{
		return array_map(
			static fn(array $row): int => (int) $row['id'],
			$db->execute('SELECT id FROM quma_after_commit ORDER BY id')->all(),
		);
	}
}
