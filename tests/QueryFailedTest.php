<?php

declare(strict_types=1);

namespace Celema\Quma\Tests;

use Celema\Quma\Database;
use Celema\Quma\DriverError;
use Celema\Quma\Exception\CheckViolation;
use Celema\Quma\Exception\ConnectionLost;
use Celema\Quma\Exception\DataError;
use Celema\Quma\Exception\ExclusionViolation;
use Celema\Quma\Exception\ForeignKeyViolation;
use Celema\Quma\Exception\IntegrityViolation;
use Celema\Quma\Exception\NotNullViolation;
use Celema\Quma\Exception\QueryFailed;
use Celema\Quma\Exception\TransactionConflict;
use Celema\Quma\Exception\UniqueViolation;
use Celema\Quma\LoadedScript;
use Celema\Quma\Script;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
final class QueryFailedTest extends TestCase
{
	private ?Database $db = null;

	protected function tearDown(): void
	{
		if ($this->db !== null) {
			$this->db->reconnect();
			$this->db->execute('DROP TABLE IF EXISTS quma_err_child')->run();
			$this->db->execute('DROP TABLE IF EXISTS quma_err_parent')->run();
			$this->db->execute('DROP TABLE IF EXISTS quma_err_strict')->run();
			$this->db->disconnect();
		}

		parent::tearDown();
	}

	/**
	 * @param class-string<QueryFailed> $class
	 */
	#[DataProvider('failures')]
	public function testClassifiesFailures(string $driver, string $sql, string $class, ?string $constraint): void
	{
		$db = $this->tables($driver);

		try {
			$db->execute($sql)->run();
			$this->fail('Expected the statement to fail.');
		} catch (QueryFailed $e) {
			$this->assertSame($class, $e::class, $e->getMessage());
			$this->assertSame($constraint, $e->constraint);
		}
	}

	/** @return iterable<string, array{string, string, class-string<QueryFailed>, ?string}> */
	public static function failures(): iterable
	{
		$statements = [
			'unique' => ['INSERT INTO quma_err_parent (id, u) VALUES (2, 1)', UniqueViolation::class],
			'primary key' => ['INSERT INTO quma_err_parent (id) VALUES (1)', UniqueViolation::class],
			'foreign key of child' => ['INSERT INTO quma_err_child (id, p) VALUES (2, 99)', ForeignKeyViolation::class],
			'foreign key of parent' => ['DELETE FROM quma_err_parent WHERE id = 1', ForeignKeyViolation::class],
			'not null' => ['INSERT INTO quma_err_parent (id, nn) VALUES (3, NULL)', NotNullViolation::class],
			'check' => ['INSERT INTO quma_err_parent (id, ck) VALUES (4, -1)', CheckViolation::class],
			'syntax' => ['SELEC 1', QueryFailed::class],
			'missing table' => ['SELECT * FROM quma_err_none', QueryFailed::class],
		];
		$constraints = [
			'pgsql' => [
				'unique' => 'quma_err_u',
				'primary key' => 'quma_err_parent_pkey',
				'foreign key of child' => 'quma_err_fk',
				'foreign key of parent' => 'quma_err_fk',
				'check' => 'quma_err_ck',
			],
			'mysql' => [
				'unique' => 'quma_err_u',
				'primary key' => 'PRIMARY',
				'foreign key of child' => 'quma_err_fk',
				'foreign key of parent' => 'quma_err_fk',
				'check' => 'quma_err_ck',
			],
			'sqlite' => [
				'unique' => 'quma_err_parent.u',
				'primary key' => 'quma_err_parent.id',
				'not null' => 'quma_err_parent.nn',
				'check' => 'quma_err_ck',
			],
		];

		foreach ($constraints as $driver => $names) {
			foreach ($statements as $label => [$sql, $class]) {
				yield "{$driver} {$label}" => [$driver, $sql, $class, $names[$label] ?? null];
			}
		}

		yield 'pgsql exclusion' => [
			'pgsql',
			'INSERT INTO quma_err_parent (id, ex) VALUES (5, 7), (6, 7)',
			ExclusionViolation::class,
			'quma_err_ex',
		];
		yield 'pgsql too long' => [
			'pgsql',
			"INSERT INTO quma_err_parent (id, s) VALUES (5, 'abcdefgh')",
			DataError::class,
			null,
		];
		yield 'pgsql invalid number' => [
			'pgsql',
			"INSERT INTO quma_err_parent (id, n) VALUES (5, 'abc')",
			DataError::class,
			null,
		];
		yield 'pgsql division by zero' => ['pgsql', 'SELECT 1 / 0', DataError::class, null];
		yield 'mysql too long' => [
			'mysql',
			"INSERT INTO quma_err_parent (id, s) VALUES (5, 'abcdefgh')",
			DataError::class,
			null,
		];
		yield 'mysql invalid number' => [
			'mysql',
			"INSERT INTO quma_err_parent (id, n) VALUES (5, 'abc')",
			DataError::class,
			null,
		];
		yield 'sqlite strict type' => [
			'sqlite',
			"INSERT INTO quma_err_strict (a) VALUES ('abc')",
			DataError::class,
			null,
		];
		yield 'sqlite rowid type' => [
			'sqlite',
			"INSERT INTO quma_err_parent (id) VALUES ('abc')",
			DataError::class,
			null,
		];
	}

	public function testKeepsTheDriversExceptionDetails(): void
	{
		$db = $this->tables('sqlite');

		try {
			$db->execute('INSERT INTO quma_err_parent (id) VALUES (1)')->run();
			$this->fail('Expected the statement to fail.');
		} catch (UniqueViolation $e) {
			$previous = $e->getPrevious();
			$this->assertInstanceOf(IntegrityViolation::class, $e);
			$this->assertInstanceOf(PDOException::class, $e);
			$this->assertInstanceOf(PDOException::class, $previous);
			$this->assertSame($previous->getMessage(), $e->getMessage());
			$this->assertSame('23000', $e->getCode());
			$this->assertSame($previous->errorInfo, $e->errorInfo);
			$this->assertNull($e->script);
		}
	}

	public function testNamesTheScriptOfAFailure(): void
	{
		$script = new Script($this->tables('sqlite'), new LoadedScript('SELEC 1', 'users/broken.sql'), false);

		try {
			$script->invoke()->run();
			$this->fail('Expected the statement to fail.');
		} catch (QueryFailed $e) {
			$this->assertSame('users/broken.sql', $e->script);
		}
	}

	public function testClassifiesAFailedCommit(): void
	{
		$db = $this->tables('pgsql');
		$db->execute('CREATE TEMPORARY TABLE quma_err_deferred (id INT UNIQUE DEFERRABLE INITIALLY DEFERRED)')->run();
		$db->begin();
		$db->execute('INSERT INTO quma_err_deferred VALUES (1), (1)')->run();

		$this->expectException(UniqueViolation::class);

		$db->commit();
	}

	public function testClassifiesALostConnectionOnPostgres(): void
	{
		$db = $this->tables('pgsql');
		$db->getConn();

		foreach (['SELECT pg_terminate_backend(pg_backend_pid())', 'SELECT 1'] as $sql) {
			try {
				$db->execute($sql)->all();
				$this->fail('Expected the statement to fail.');
			} catch (ConnectionLost $e) {
				$this->assertSame('HY000', $e->getCode());
			}
		}
	}

	public function testClassifiesALostConnectionOnMysql(): void
	{
		$db = $this->tables('mysql');
		$id = $db->execute('SELECT CONNECTION_ID() AS id')->one()['id'];
		$killer = new Database($this->connection(dsn: $this->dsn('mysql')));
		$killer->execute("KILL {$id}")->run();

		$this->expectException(ConnectionLost::class);

		$db->execute('SELECT 1')->all();
	}

	public function testClassifiesLockConflictOnPostgres(): void
	{
		$db = $this->tables('pgsql');
		$other = new Database($this->connection(dsn: $this->dsn('pgsql')));
		$db->begin();
		$db->execute('SELECT * FROM quma_err_parent FOR UPDATE')->all();
		$other->begin();

		try {
			$this->expectException(TransactionConflict::class);
			$other->execute('SELECT * FROM quma_err_parent FOR UPDATE NOWAIT')->all();
		} finally {
			$other->rollback();
			$db->rollback();
		}
	}

	public function testClassifiesLockConflictOnMysql(): void
	{
		$db = $this->tables('mysql');
		$other = new Database($this->connection(dsn: $this->dsn('mysql')));
		$other->execute('SET innodb_lock_wait_timeout = 1')->run();
		$db->begin();
		$db->execute('UPDATE quma_err_parent SET u = u WHERE id = 1')->run();
		$other->begin();

		try {
			$this->expectException(TransactionConflict::class);
			$other->execute('UPDATE quma_err_parent SET u = u WHERE id = 1')->run();
		} finally {
			$other->rollback();
			$db->rollback();
		}
	}

	public function testClassifiesLockConflictOnSqlite(): void
	{
		$file = sys_get_temp_dir() . '/quma-locked-' . uniqid() . '.sqlite3';

		try {
			$first = new Database($this->connection(dsn: "sqlite:{$file}")->option(PDO::ATTR_TIMEOUT, 0));
			$second = new Database($this->connection(dsn: "sqlite:{$file}")->option(PDO::ATTR_TIMEOUT, 0));
			$first->execute('CREATE TABLE t (a INT)')->run();
			$first->begin();
			$first->execute('INSERT INTO t VALUES (1)')->run();

			$this->expectException(TransactionConflict::class);

			$second->execute('INSERT INTO t VALUES (2)')->run();
		} finally {
			unset($first, $second);
			unlink($file);
		}
	}

	/**
	 * @param array{0?: string, 1?: int, 2?: string}|null $info
	 * @param class-string<QueryFailed> $class
	 */
	#[DataProvider('driverErrors')]
	#[DataProvider('standardStates')]
	#[DataProvider('mysqlCodes')]
	public function testClassifiesWhatOnlySomeServersReport(
		string $driver,
		string $code,
		?array $info,
		string $class,
	): void {
		$e = new PDOException('failed');
		$e->errorInfo = $info;
		$reflection = new \ReflectionProperty(\Exception::class, 'code');
		$reflection->setValue($e, $code);

		$this->assertSame($class, new DriverError($driver, $e)->exception());
	}

	/** @return array<string, array{string, string, ?array{0?: string, 1?: int, 2?: string}, class-string<QueryFailed>}> */
	public static function driverErrors(): array
	{
		return [
			'no error info' => ['pgsql', '23505', null, UniqueViolation::class],
			'error info before the code' => ['pgsql', 'HY000', ['23505', 7, 'x'], UniqueViolation::class],
			'other driver' => ['odbc', '23505', ['23505', 1, 'x'], UniqueViolation::class],
			'restrict violation' => ['pgsql', '23001', ['23001', 7, 'x'], ForeignKeyViolation::class],
			'serialization' => ['pgsql', '40001', ['40001', 7, 'x'], TransactionConflict::class],
			'deadlock' => ['pgsql', '40P01', ['40P01', 7, 'x'], TransactionConflict::class],
			'admin shutdown' => ['pgsql', '57P01', ['57P01', 7, 'x'], ConnectionLost::class],
			'connection class' => ['pgsql', '08006', ['08006', 7, 'x'], ConnectionLost::class],
			'other integrity' => ['pgsql', '23000', ['23000', 7, 'x'], IntegrityViolation::class],
			'closed by server' => [
				'pgsql',
				'HY000',
				['HY000', 7, 'server closed the connection unexpectedly'],
				ConnectionLost::class,
			],
			'mariadb check' => ['mysql', '23000', ['23000', 4025, 'CONSTRAINT `c` failed'], CheckViolation::class],
			'mysql deadlock' => ['mysql', '40001', ['40001', 1213, 'x'], TransactionConflict::class],
			'mysql lost during query' => ['mysql', 'HY000', ['HY000', 2013, 'x'], ConnectionLost::class],
			'mysql other integrity' => ['mysql', '23000', ['23000', 1, 'x'], IntegrityViolation::class],
			'mysql other' => ['mysql', '42000', ['42000', 1064, 'x'], QueryFailed::class],
			'sqlite table locked' => ['sqlite', 'HY000', ['HY000', 6, 'x'], TransactionConflict::class],
		];
	}

	/** @return iterable<string, array{string, string, array{string, int, string}, class-string<QueryFailed>}> */
	public static function standardStates(): iterable
	{
		$states = [
			UniqueViolation::class => ['23505'],
			ForeignKeyViolation::class => ['23503'],
			NotNullViolation::class => ['23502'],
			CheckViolation::class => ['23514'],
			ExclusionViolation::class => ['23P01'],
			TransactionConflict::class => ['55P03'],
			ConnectionLost::class => ['57P02', '57P03'],
		];

		foreach ($states as $class => $values) {
			foreach ($values as $state) {
				yield "state {$state}" => ['pgsql', $state, [$state, 7, 'x'], $class];
			}
		}
	}

	/** @return iterable<string, array{string, string, array{string, int, string}, class-string<QueryFailed>}> */
	public static function mysqlCodes(): iterable
	{
		$codes = [
			UniqueViolation::class => [1062, 1169, 1586],
			ForeignKeyViolation::class => [1216, 1217, 1451, 1452],
			NotNullViolation::class => [1048, 1364],
			CheckViolation::class => [3819],
			DataError::class => [1264, 1265, 1292, 1366, 1406],
			TransactionConflict::class => [1205],
			ConnectionLost::class => [1053, 1927, 2006, 2055],
		];

		foreach ($codes as $class => $values) {
			foreach ($values as $code) {
				yield "code {$code}" => ['mysql', 'HY000', ['HY000', $code, 'x'], $class];
			}
		}
	}

	#[DataProvider('bindingDrivers')]
	public function testWrapsAFailureWhileBinding(string $driver): void
	{
		$connection = $this->connection(dsn: $this->dsn($driver))->option(PDO::ATTR_EMULATE_PREPARES, false);

		$this->expectException(QueryFailed::class);
		$this->expectExceptionMessage('Invalid parameter number');

		new Database($connection)->execute('SELECT 1 AS v', ['unused' => 1])->run();
	}

	/** @return array<string, array{string}> */
	public static function bindingDrivers(): array
	{
		return ['pgsql' => ['pgsql'], 'mysql' => ['mysql']];
	}

	public function testHasNoConstraintWithoutANameInTheMessage(): void
	{
		$e = new PDOException('failed');
		$e->errorInfo = ['23502', 7, 'ERROR:  null value in column "a" violates not-null constraint'];

		$this->assertNull(new DriverError('pgsql', $e)->constraint());
	}

	private function tables(string $driver): Database
	{
		$dsn = $this->dsn($driver);
		$db = new Database($this->connection(dsn: $dsn));
		$this->db = $db;
		$id = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY' : 'INT PRIMARY KEY';
		$exclude = $driver === 'pgsql' ? ', ex INT, CONSTRAINT quma_err_ex EXCLUDE (ex WITH =)' : '';

		$db->execute('DROP TABLE IF EXISTS quma_err_child')->run();
		$db->execute('DROP TABLE IF EXISTS quma_err_parent')->run();
		$db
			->execute(
				"CREATE TABLE quma_err_parent (id {$id}, u INT, nn INT NOT NULL DEFAULT 1, ck INT, s VARCHAR(3), n INT{$exclude},"
					. ' CONSTRAINT quma_err_u UNIQUE (u), CONSTRAINT quma_err_ck CHECK (ck > 0))',
			)
			->run();
		$db
			->execute(
				"CREATE TABLE quma_err_child (id {$id}, p INT,"
					. ' CONSTRAINT quma_err_fk FOREIGN KEY (p) REFERENCES quma_err_parent (id))',
			)
			->run();

		if ($driver === 'sqlite') {
			$db->execute('PRAGMA foreign_keys = ON')->run();
			$db->execute('DROP TABLE IF EXISTS quma_err_strict')->run();
			$db->execute('CREATE TABLE quma_err_strict (a INT) STRICT')->run();
		}

		$db->execute('INSERT INTO quma_err_parent (id, u) VALUES (1, 1)')->run();
		$db->execute('INSERT INTO quma_err_child (id, p) VALUES (1, 1)')->run();

		return $db;
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
