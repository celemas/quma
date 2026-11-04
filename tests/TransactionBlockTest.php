<?php

declare(strict_types=1);

namespace Celema\Quma\Tests;

use Celema\Quma\Database;
use LogicException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * @internal
 */
final class TransactionBlockTest extends TestCase
{
	/** @return array<string, array{string}> */
	public static function drivers(): array
	{
		return ['sqlite' => ['sqlite'], 'pgsql' => ['pgsql'], 'mysql' => ['mysql']];
	}

	#[DataProvider('drivers')]
	public function testCommitsWhenTheBlockReturnsAndReturnsItsResult(string $driver): void
	{
		$db = $this->db($driver);

		$result = $db->transaction(function () use ($db): string {
			$this->add($db, 1);

			return 'done';
		});

		$this->assertSame('done', $result);
		$this->assertFalse($db->getConn()->inTransaction());
		$this->assertSame([1], $this->rows($db));
	}

	#[DataProvider('drivers')]
	public function testRollsBackAndRethrowsWhenTheBlockThrows(string $driver): void
	{
		$db = $this->db($driver);
		$failure = new LogicException('failed');

		try {
			$db->transaction(function () use ($db, $failure): void {
				$this->add($db, 1);

				throw $failure;
			});
			$this->fail('Expected the exception of the block.');
		} catch (LogicException $e) {
			$this->assertSame($failure, $e);
		}

		$this->assertFalse($db->getConn()->inTransaction());
		$this->assertSame([], $this->rows($db));
	}

	#[DataProvider('drivers')]
	public function testNestedBlockUndoesOnlyItsOwnWork(string $driver): void
	{
		$db = $this->db($driver);

		$db->transaction(function () use ($db): void {
			$this->add($db, 1);

			try {
				$db->transaction(function () use ($db): void {
					$this->add($db, 2);

					throw new LogicException('failed');
				});
			} catch (LogicException) {
				// @mago-expect lint:no-empty-catch-clause The outer block goes on.
			}

			$this->add($db, 3);
		});

		$this->assertSame([1, 3], $this->rows($db));
	}

	#[DataProvider('drivers')]
	public function testOutermostBlockDecidesAboutCompletedNestedBlocks(string $driver): void
	{
		$db = $this->db($driver);

		try {
			$db->transaction(function () use ($db): void {
				$db->transaction(fn() => $this->add($db, 1));
				$db->transaction(fn() => $this->add($db, 2));

				throw new LogicException('failed');
			});
		} catch (LogicException) {
			// @mago-expect lint:no-empty-catch-clause The rows below show the outcome.
		}

		$this->assertSame([], $this->rows($db));
	}

	#[DataProvider('drivers')]
	public function testDeeplyNestedBlocksUseTheirOwnSavepoints(string $driver): void
	{
		$db = $this->db($driver);

		$db->transaction(function () use ($db): void {
			$this->add($db, 1);
			$db->transaction(function () use ($db): void {
				$this->add($db, 2);

				try {
					$db->transaction(function () use ($db): void {
						$this->add($db, 3);

						throw new LogicException('failed');
					});
				} catch (LogicException) {
					// @mago-expect lint:no-empty-catch-clause The middle block goes on.
				}

				$this->add($db, 4);
			});
		});

		$this->assertSame([1, 2, 4], $this->rows($db));
	}

	#[DataProvider('drivers')]
	public function testBlockInsideBeginLeavesTheTransactionOpen(string $driver): void
	{
		$db = $this->db($driver);
		$db->begin();

		$db->transaction(fn() => $this->add($db, 1));

		$this->assertTrue($db->getConn()->inTransaction());
		$db->rollback();
		$this->assertSame([], $this->rows($db));
	}

	#[DataProvider('drivers')]
	public function testFailedStatementInANestedBlockKeepsTheOuterTransactionUsable(string $driver): void
	{
		$db = $this->db($driver);

		$db->transaction(function () use ($db): void {
			$this->add($db, 1);

			try {
				$db->transaction(static fn() => $db->execute('SELECT * FROM quma_missing_table')->all());
			} catch (PDOException) {
				// @mago-expect lint:no-empty-catch-clause PostgreSQL would abort the transaction without the savepoint.
			}

			$this->add($db, 2);
		});

		$this->assertSame([1, 2], $this->rows($db));
	}

	public function testFailedStatementSwallowedInABlockFailsTheBlockOnPostgres(): void
	{
		$db = $this->db('pgsql');

		$this->expectException(PDOException::class);

		try {
			$db->transaction(static function () use ($db): void {
				$db->transaction(static function () use ($db): void {
					try {
						$db->execute('SELECT * FROM quma_missing_table')->all();
					} catch (PDOException) {
						// @mago-expect lint:no-empty-catch-clause The savepoint cannot be released after this.
					}
				});
			});
		} finally {
			$this->assertFalse($db->getConn()->inTransaction());
		}
	}

	public function testBlockThatFailsToFinishKeepsTheOuterTransactionUsable(): void
	{
		$db = $this->db('pgsql');

		$db->transaction(function () use ($db): void {
			$this->add($db, 1);

			try {
				$db->transaction(static function () use ($db): void {
					try {
						$db->execute('SELECT * FROM quma_missing_table')->all();
					} catch (PDOException) {
						// @mago-expect lint:no-empty-catch-clause The savepoint cannot be released after this.
					}
				});
				$this->fail('Expected the nested block to fail.');
			} catch (PDOException) {
				// @mago-expect lint:no-empty-catch-clause The outer block goes on.
			}

			$this->add($db, 2);
		});

		$this->assertSame([1, 2], $this->rows($db));
	}

	public function testFailedNestedBlockReleasesItsSavepoint(): void
	{
		$db = $this->db('sqlite');

		$db->transaction(function () use ($db): void {
			try {
				$db->transaction(static function (): void {
					throw new LogicException('failed');
				});
			} catch (LogicException) {
				// @mago-expect lint:no-empty-catch-clause The outer block goes on.
			}

			$this->expectException(PDOException::class);
			$this->expectExceptionMessage('no such savepoint');

			$db->getConn()->exec('RELEASE SAVEPOINT quma_block_1');
		});
	}

	public function testLeavesATransactionOnAReplacedConnectionAlone(): void
	{
		$db = $this->db('sqlite');

		try {
			$db->transaction(static function () use ($db): void {
				$db->reconnect();
				$db->begin();

				throw new LogicException('failed');
			});
		} catch (LogicException) {
			// @mago-expect lint:no-empty-catch-clause The state below shows the outcome.
		}

		$this->assertTrue($db->getConn()->inTransaction());
	}

	#[DataProvider('drivers')]
	public function testRefusesCommitAndRollbackInsideABlock(string $driver): void
	{
		$db = $this->db($driver);

		$db->transaction(function () use ($db): void {
			$this->assertRefused(
				'Cannot commit inside a transaction block. Return from the block to commit or throw to roll back.',
				$db->commit(...),
			);
			$this->assertRefused(
				'Cannot roll back inside a transaction block. Return from the block to commit or throw to roll back.',
				$db->rollback(...),
			);
			$this->add($db, 1);
		});

		$this->assertSame([1], $this->rows($db));
	}

	public function testCommitAndRollbackWorkAgainAfterABlock(): void
	{
		$db = $this->db('sqlite');
		$db->transaction(static fn(): int => 1);

		$db->begin();
		$this->assertTrue($db->commit());
		$db->begin();
		$this->assertTrue($db->rollback());

		try {
			$db->transaction(static function (): void {
				throw new LogicException('failed');
			});
		} catch (LogicException) {
			// @mago-expect lint:no-empty-catch-clause Commit below shows the block ended.
		}

		$db->begin();
		$this->assertTrue($db->commit());
	}

	public function testFailedCommitIsReportedAndEndsTheTransaction(): void
	{
		$db = $this->db('pgsql');
		$db
			->execute('CREATE TEMPORARY TABLE quma_deferred (id INT UNIQUE DEFERRABLE INITIALLY DEFERRED)')
			->run();

		$this->expectException(PDOException::class);
		$this->expectExceptionMessage('duplicate key');

		try {
			$db->transaction(static function () use ($db): void {
				$db->execute('INSERT INTO quma_deferred VALUES (1), (1)')->run();
			});
		} finally {
			$this->assertFalse($db->getConn()->inTransaction());
		}
	}

	public function testImplicitCommitOnMysqlEndsTheBlockWithoutFailure(): void
	{
		$db = $this->db('mysql');

		try {
			$db->transaction(function () use ($db): void {
				$this->add($db, 1);
				$db->execute('CREATE TABLE quma_implicit (id INT)')->run();
			});
			$db->transaction(static function () use ($db): void {
				$db->transaction(static function () use ($db): void {
					$db->execute('DROP TABLE quma_implicit')->run();
				});
			});
		} finally {
			$db->execute('DROP TABLE IF EXISTS quma_implicit')->run();
		}

		$this->assertSame([1], $this->rows($db));
	}

	public function testRefusesToFinishABlockOnAReplacedConnection(): void
	{
		$db = $this->db('sqlite');
		$before = $db->getConn();

		try {
			$db->transaction(static function () use ($db): void {
				$db->reconnect();
			});
			$this->fail('Expected the block to fail.');
		} catch (RuntimeException $e) {
			$this->assertSame('The connection was replaced inside a transaction block.', $e->getMessage());
		}

		$this->assertNotSame($before, $db->getConn());
		$this->assertFalse($db->getConn()->inTransaction());
	}

	public function testKeepsTheExceptionOfTheBlockWhenTheConnectionWasDropped(): void
	{
		$db = $this->db('sqlite');
		$failure = new LogicException('failed');

		try {
			$db->transaction(static function () use ($db, $failure): void {
				$db->disconnect();

				throw $failure;
			});
			$this->fail('Expected the exception of the block.');
		} catch (LogicException $e) {
			$this->assertSame($failure, $e);
		}
	}

	public function testKeepsTheExceptionOfTheBlockWhenTheRollbackFails(): void
	{
		$db = $this->db('sqlite');
		$failure = new LogicException('failed');

		try {
			$db->transaction(static function () use ($db, $failure): void {
				$db->getConn()->rollBack();

				throw $failure;
			});
			$this->fail('Expected the exception of the block.');
		} catch (LogicException $e) {
			$this->assertSame($failure, $e);
		}
	}

	private function db(string $driver): Database
	{
		foreach (self::getAvailableDsns() as $dsn) {
			if (!str_starts_with($dsn, "{$driver}:")) {
				continue;
			}

			$db = new Database($this->connection(dsn: $dsn));
			$db->execute('CREATE TEMPORARY TABLE quma_block (id INT)')->run();

			return $db;
		}

		$this->markTestSkipped("{$driver} is not available.");
	}

	private function add(Database $db, int $id): void
	{
		$db->execute('INSERT INTO quma_block (id) VALUES (?)', $id)->run();
	}

	/** @return list<int> */
	private function rows(Database $db): array
	{
		return array_map(
			static fn(array $row): int => (int) $row['id'],
			$db->execute('SELECT id FROM quma_block ORDER BY id')->all(),
		);
	}

	private function assertRefused(string $message, callable $call): void
	{
		try {
			$call();
			$this->fail('Expected the call to be refused.');
		} catch (RuntimeException $e) {
			$this->assertSame($message, $e->getMessage());
		}
	}
}
