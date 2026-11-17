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
final class IsolationTest extends TestCase
{
	/** @var list<string> */
	private array $calls = [];

	/** @return array<string, array{string}> */
	public static function drivers(): array
	{
		return ['sqlite' => ['sqlite'], 'pgsql' => ['pgsql'], 'mysql' => ['mysql']];
	}

	#[DataProvider('drivers')]
	public function testTheCodesTransactionCommitsIntoTheIsolatedOne(string $driver): void
	{
		$db = $this->db($driver);
		$db->isolate();

		$this->assertTrue($db->begin());
		$this->add($db, 1);
		$this->assertTrue($db->commit());
		$this->assertSame([1], $this->rows($db));
		$this->assertTrue($db->getConn()->inTransaction());

		$db->begin();
		$this->add($db, 2);
		$db->commit();
		$db->afterCommit($this->record('after the commit'));
		$this->assertSame(['after the commit'], $this->calls);
		$this->assertSame([1, 2], $this->rows($db));

		$db->discard();
		$this->assertSame([], $this->rows($db));
		$this->assertFalse($db->getConn()->inTransaction());
	}

	#[DataProvider('drivers')]
	public function testTheCodesRollbackUndoesOnlyItsOwnWork(string $driver): void
	{
		$db = $this->db($driver);
		$db->isolate();
		$this->add($db, 1);

		$db->begin();
		$this->add($db, 2);
		$this->assertTrue($db->rollback());

		$this->assertSame([1], $this->rows($db));
		$db->discard();
	}

	#[DataProvider('drivers')]
	public function testABlockIsTheCodesOutermostTransaction(string $driver): void
	{
		$db = $this->db($driver);
		$db->isolate();

		$db->afterCommit($this->record('outside'));
		$db->transaction(function () use ($db): void {
			$this->add($db, 1);
			$db->afterCommit($this->record('committed'));
			$db->transaction(function () use ($db): void {
				$db->afterCommit($this->record('nested'));
			});

			$this->assertSame(['outside'], $this->calls);
		});

		$this->assertSame(['outside', 'committed', 'nested'], $this->calls);
		$this->assertSame([1], $this->rows($db));

		try {
			$db->transaction(function () use ($db): void {
				$this->add($db, 2);
				$db->afterCommit($this->record('failed'));

				throw new LogicException('failed');
			});
		} catch (LogicException) {
			// @mago-expect lint:no-empty-catch-clause The block failed on purpose.
		}

		$this->assertSame(['outside', 'committed', 'nested'], $this->calls);
		$this->assertSame([1], $this->rows($db));
		$db->discard();
		$this->assertSame([], $this->rows($db));
	}

	public function testAFailedNestedBlockDiscardsOnlyItsCallbacks(): void
	{
		$db = $this->db('sqlite');
		$db->isolate();

		$db->transaction(function () use ($db): void {
			$db->afterCommit($this->record('outer'));

			try {
				$db->transaction(function () use ($db): void {
					$db->afterCommit($this->record('inner'));

					throw new LogicException('inner');
				});
			} catch (LogicException) {
				// @mago-expect lint:no-empty-catch-clause The nested block failed on purpose.
			}
		});

		$this->assertSame(['outer'], $this->calls);
		$db->discard();
	}

	public function testTransactionsFailAsTheyWouldWithoutIsolation(): void
	{
		$db = $this->db('sqlite');
		$db->isolate();

		$this->assertFails('There is no active transaction', $db->commit(...));
		$this->assertFails('There is no active transaction', $db->rollback(...));

		$db->begin();
		$this->assertFails('There is already an active transaction', $db->begin(...));
		$db->rollback();

		$this->assertFails('There is already an active transaction', $db->isolate(...));
		$db->discard();
	}

	#[DataProvider('drivers')]
	public function testResetEndsOnlyTheCodesTransaction(string $driver): void
	{
		$db = $this->db($driver);
		$db->isolate();
		$this->add($db, 1);
		$this->assertTrue($db->reset());

		$db->begin();
		$this->add($db, 2);
		$db->afterCommit($this->record('reset'));
		$this->assertTrue($db->reset());

		$this->assertTrue($db->connected());
		$this->assertSame([1], $this->rows($db));
		$db->begin();
		$db->commit();
		$this->assertSame([], $this->calls);
		$db->discard();
	}

	public function testDiscardClosesAnOpenStream(): void
	{
		$db = $this->db('sqlite');
		$db->isolate();
		$this->add($db, 1);
		$stream = $db->execute('SELECT id FROM quma_isolation')->stream();
		$stream->current();

		$db->discard();

		$this->assertSame([], $this->rows($db));
		unset($stream);
	}

	public function testDisconnectEndsOnlyTheCodesTransaction(): void
	{
		$db = $this->db('sqlite');
		$db->isolate();
		$this->add($db, 1);
		$db->begin();
		$this->add($db, 2);

		$db->disconnect();

		$this->assertTrue($db->connected());
		$this->assertSame([1], $this->rows($db));
		$db->discard();
		$this->assertSame([], $this->rows($db));
	}

	public function testACommitAfterAFailedStatementLeavesTheIsolatedTransactionUsable(): void
	{
		$db = $this->db('pgsql');
		$db->isolate();
		$this->add($db, 1);
		$db->begin();
		$this->add($db, 2);

		try {
			$db->execute('SELECT missing FROM quma_isolation')->run();
		} catch (PDOException) {
			// @mago-expect lint:no-empty-catch-clause The statement fails on purpose and aborts the transaction.
		}

		$this->assertFails('current transaction is aborted', $db->commit(...));
		$this->assertSame([1], $this->rows($db));
		$db->begin();
		$this->assertTrue($db->commit());
		$db->discard();
	}

	public function testALostConnectionIsDroppedByResetAndDiscard(): void
	{
		$db = $this->db('pgsql');
		$db->isolate();
		$db->begin();
		$this->terminate($db);

		$this->assertFalse($db->reset());
		$this->assertFalse($db->connected());
		// The isolation ended with its connection; the next one works as usual.
		$this->assertTrue($db->begin());
		$this->assertTrue($db->rollback());

		$db = $this->db('pgsql');
		$db->isolate();
		$this->terminate($db);

		$db->discard();
		$this->assertFalse($db->connected());
	}

	public function testAnImplicitCommitOnMysqlEndsTheCodesTransaction(): void
	{
		$db = $this->db('mysql');
		$db->isolate();

		try {
			$db->transaction(function () use ($db): void {
				$db->afterCommit($this->record('implicit'));
				$db->execute('CREATE TABLE quma_isolation_implicit (id INT)')->run();
			});

			$db->afterCommit($this->record('after'));
			$this->assertSame(['implicit', 'after'], $this->calls);
			// The implicit commit ended the isolation too.
			$this->assertTrue($db->begin());
			$this->assertTrue($db->rollback());
		} finally {
			$db->discard();
			$db->execute('DROP TABLE IF EXISTS quma_isolation_implicit')->run();
		}
	}

	/** @param \Closure(): mixed $call */
	private function assertFails(string $message, \Closure $call): void
	{
		try {
			$call();
			$this->fail("Expected: {$message}");
		} catch (PDOException $e) {
			$this->assertStringContainsString($message, $e->getMessage());
		}
	}

	private function terminate(Database $db): void
	{
		try {
			$db->getConn()->exec('SELECT pg_terminate_backend(pg_backend_pid())');
		} catch (PDOException) {
			// @mago-expect lint:no-empty-catch-clause The connection ends on purpose.
		}
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
			$db->execute('CREATE TEMPORARY TABLE quma_isolation (id INT)')->run();

			return $db;
		}

		$this->markTestSkipped("{$driver} is not available.");
	}

	private function add(Database $db, int $id): void
	{
		$db->execute('INSERT INTO quma_isolation (id) VALUES (?)', $id)->run();
	}

	/** @return list<int> */
	private function rows(Database $db): array
	{
		return array_map(
			static fn(array $row): int => (int) $row['id'],
			$db->execute('SELECT id FROM quma_isolation ORDER BY id')->all(),
		);
	}
}
