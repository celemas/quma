<?php

declare(strict_types=1);

namespace Celema\Quma\Tests;

use Celema\Quma\Database;
use Celema\Quma\Exception\UnexpectedResultCount;
use Celema\Quma\Tests\Util\InspectableDatabase;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;

/**
 * @internal
 */
class DatabaseTest extends TestCase
{
	public const NUMBER_OF_ALBUMS = 7;
	public const NUMBER_OF_MEMBERS = 17;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
		self::createTestDb();
	}

	public function testDatabaseConnection(): void
	{
		$db = new Database($this->connection());

		$this->assertInstanceOf(PDO::class, $db->getConn());
	}

	public function testPdoOptionsCanOverrideDefaults(): void
	{
		$conn = $this->connection()->option(PDO::ATTR_CASE, PDO::CASE_UPPER);
		$db = new Database($conn);

		$this->assertSame(PDO::CASE_UPPER, $db->getConn()->getAttribute(PDO::ATTR_CASE));
	}

	public function testPdoErrmodeAlwaysUsesExceptions(): void
	{
		$conn = $this->connection()->option(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
		$db = new Database($conn);

		$this->assertSame(PDO::ERRMODE_EXCEPTION, $db->getConn()->getAttribute(PDO::ATTR_ERRMODE));
	}

	public function testDatabaseTracksConnectionState(): void
	{
		$db = new InspectableDatabase($this->connection());

		$this->assertFalse($db->connected());
		$this->assertNull($db->connectedAtPublic());
		$this->assertNull($db->lastUsedAtPublic());

		$db->connect();

		$this->assertTrue($db->connected());
		$this->assertIsInt($db->connectedAtPublic());
		$this->assertIsInt($db->lastUsedAtPublic());
		$this->assertGreaterThanOrEqual($db->connectedAtPublic(), $db->lastUsedAtPublic());

		$db->disconnect();

		$this->assertFalse($db->connected());
		$this->assertNull($db->connectedAtPublic());
		$this->assertNull($db->lastUsedAtPublic());
	}

	public function testReconnectCreatesANewPdoConnection(): void
	{
		$db = new Database($this->connection());
		$first = $db->getConn();

		$db->reconnect();
		$second = $db->getConn();

		$this->assertNotSame($first, $second);
	}

	public function testPingReturnsConnectionHealth(): void
	{
		$db = new Database($this->connection());
		$this->assertFalse($db->ping());

		$db->connect();
		$this->assertTrue($db->ping());

		$db->disconnect();
		$this->assertFalse($db->ping());
	}

	public function testPingReturnsFalseWhenQueryReturnsFalse(): void
	{
		$db = new InspectableDatabase($this->connection());

		$pdo = new class('sqlite::memory:') extends PDO {
			public function query(
				string $query,
				?int $fetchMode = null,
				mixed ...$fetchModeArgs,
			): PDOStatement|false {
				return false;
			}
		};

		$db->setPdoPublic($pdo);

		$this->assertFalse($db->ping());
	}

	public function testPingReturnsFalseWhenQueryThrows(): void
	{
		$db = new InspectableDatabase($this->connection());

		$pdo = new class('sqlite::memory:') extends PDO {
			public function query(
				string $query,
				?int $fetchMode = null,
				mixed ...$fetchModeArgs,
			): PDOStatement|false {
				throw new RuntimeException('ping failed');
			}
		};

		$db->setPdoPublic($pdo);

		$this->assertFalse($db->ping());
	}

	public function testResetRollsBackOpenTransactions(): void
	{
		$db = $this->getDb();

		$db->begin();
		$db->members->add('Tim Aymar', 1998, 2001)->run();
		$this->assertTrue($db->getConn()->inTransaction());

		$db->reset();

		$this->assertFalse($db->getConn()->inTransaction());
		$this->assertCount(self::NUMBER_OF_MEMBERS, $db->members->list()->all());
	}

	public function testResetWithoutConnectionIsNoOp(): void
	{
		$db = new Database($this->connection());
		$db->reset();

		$this->assertFalse($db->connected());
	}

	public function testDisconnectIgnoresRollbackProbeFailures(): void
	{
		$db = new InspectableDatabase($this->connection());

		$pdo = new class('sqlite::memory:') extends PDO {
			public function inTransaction(): bool
			{
				throw new RuntimeException('disconnect probe failed');
			}
		};

		$db->setPdoPublic($pdo);
		$db->disconnect();

		$this->assertFalse($db->connected());
	}

	public function testGetConnThrowsWhenConnectionWasNotInitialized(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Database connection not initialized');

		$db = new InspectableDatabase($this->connection());
		$db->disableConnect();

		$db->getConn();
	}

	#[DataProvider('debugFlagProvider')]
	public function testDebugPrintEnvUsesPositiveFlagValues(string $flag, bool $prints): void
	{
		$output = '';

		$this->withEnv('QUMA_DEBUG', '1', function () use ($flag, &$output): void {
			$this->withEnv('QUMA_DEBUG_PRINT', $flag, function () use (&$output): void {
				ob_start();

				try {
					$this->getDb()->execute('SELECT name FROM members WHERE member = ?', 1);
				} finally {
					$buffer = ob_get_clean();
					$output = is_string($buffer) ? $buffer : '';
				}
			});
		});

		if ($prints) {
			$this->assertStringContainsString('SELECT name FROM members WHERE member = 1', $output);
		} else {
			$this->assertSame('', $output);
		}
	}

	public static function debugFlagProvider(): array
	{
		return [
			'one' => ['1', true],
			'true' => ['true', true],
			'yes' => ['yes', true],
			'on' => ['on', true],
			'uppercase' => ['YES', true],
			'padded' => [' on ', true],
			'zero' => ['0', false],
			'false' => ['false', false],
			'no' => ['no', false],
			'off' => ['off', false],
			'unknown' => ['foo', false],
		];
	}

	#[DataProvider('debugFlagProvider')]
	public function testDebugFlagReadsEnvironmentWhenDatabaseIsCreated(string $flag, bool $debug): void
	{
		$disabled = $this->getDb();
		$this->assertFalse($disabled->debug);

		$enabled = $this->withEnv('QUMA_DEBUG', $flag, $this->getDb(...));
		$this->assertSame($debug, $enabled->debug);
	}

	public function testPdoQuote(): void
	{
		$db = $this->getDb();

		$this->assertSame(
			'\'Co\'\'mpl\'\'\'\'ex "st\'\'"ring\'',
			$db->quote("Co'mpl''ex \"st'\"ring"),
		);
	}

	public function testFetchAllQueryAll(): void
	{
		$db = $this->getDb();
		$result = $db->members->list()->all();

		$this->assertCount(self::NUMBER_OF_MEMBERS, $result);
	}

	public function testFetchLazyQueryLazy(): void
	{
		$db = $this->getDb();
		$result = $db->members->list()->lazy();

		$this->assertCount(self::NUMBER_OF_MEMBERS, iterator_to_array($result));
	}

	public function testGetRowCountQueryLen(): void
	{
		// SQLite unlike MySQL/Postgres always returns 0.
		// So this tests does not check for correct result
		// but if the code runs without errors.
		$db = $this->getDb();
		$result = $db->members->list()->len();

		$this->assertSame(0, $result);
	}

	public function testOneReturnsTheOnlyResult(): void
	{
		$db = $this->getDb();
		$result = $db->members->byId(2)->one();

		$this->assertSame('Rick Rozz', $result['name']);
	}

	public function testOneThrowsWhenNoResultExists(): void
	{
		$this->expectException(UnexpectedResultCount::class);
		$this->expectExceptionMessage('Expected exactly one result, got none.');

		$this->getDb()->execute('SELECT name FROM members WHERE member = -1')->one();
	}

	public function testOneThrowsWhenMultipleResultsExist(): void
	{
		$this->expectException(UnexpectedResultCount::class);
		$this->expectExceptionMessage('Expected exactly one result, got more than one.');

		$this->getDb()->members->list()->one();
	}

	public function testFirstReturnsFirstResultWithoutAdvancing(): void
	{
		$query = $this->getDb()->members->list();
		$first = $query->first();
		$again = $query->first();

		$this->assertSame('Chuck Schuldiner', $first['name']);
		$this->assertSame($first, $again);
		$this->assertNull($this->getDb()->execute('SELECT name FROM members WHERE member = -1')->first());
	}

	public function testRunOnlyQueriesQueryRun(): void
	{
		$db = $this->getDb();

		$db->members->add('Tim Aymar', 1998, 2001)->run();
		$this->assertCount(self::NUMBER_OF_MEMBERS + 1, $db->members->list()->all());
		$db->members->delete(['name' => 'Tim Aymar'])->run();
		$this->assertCount(self::NUMBER_OF_MEMBERS, $db->members->list()->all());
	}

	public function testTransactionsBeginCommit(): void
	{
		$db = $this->getDb();

		$db->begin();
		$db->members->add('Tim Aymar', 1998, 2001)->run();
		$db->commit();
		$this->assertCount(self::NUMBER_OF_MEMBERS + 1, $db->members->list()->all());

		$db->members->delete(['name' => 'Tim Aymar'])->run();

		$db->begin();
		$db->members->add('Tim Aymar', 1998, 2001)->run();
		$db->rollback();
		$this->assertCount(self::NUMBER_OF_MEMBERS, $db->members->list()->all());
	}

	public function testQueryWithPositionalParameters(): void
	{
		$db = $this->getDb();
		$result = $db->members->byId(2)->one();
		$this->assertSame('Rick Rozz', $result['name']);

		// arguments can also be passed as array
		$result = $db->members->byId([4])->one();
		$this->assertSame('Terry Butler', $result['name']);
	}

	public function testQueryWithNamedParameters(): void
	{
		$db = $this->getDb();
		$result = $db->members->activeFromTo([
			'from' => 1990,
			'to' => 1995,
		])->all();

		$this->assertCount(7, $result);
	}

	public function testQueryWithStringParameters(): void
	{
		$db = $this->getDb();
		$query = $db->types->test([
			'val' => 'Death',
		]);

		$this->assertSame("SELECT * FROM typetest WHERE val = 'Death';\n", (string) $query);
	}

	public function testQueryWithBooleanParameters(): void
	{
		$db = $this->getDb();
		$query = $db->types->test([
			'val' => true,
		]);

		$this->assertSame("SELECT * FROM typetest WHERE val = true;\n", (string) $query);
	}

	public function testQueryWithNullParameters(): void
	{
		$db = $this->getDb();
		$query = $db->types->test([
			'val' => null,
		]);

		$this->assertSame("SELECT * FROM typetest WHERE val = NULL;\n", (string) $query);
	}

	public function testQueryWithArrayParameters(): void
	{
		$db = $this->getDb();
		$query = $db->types->test([
			'val' => [1, 2, 3],
		]);

		$this->assertSame("SELECT * FROM typetest WHERE val = '[1,2,3]';\n", (string) $query);
	}

	public function testQueryWithInvalidArrayParameters(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Array parameters must be JSON-encodable.');

		$db = $this->getDb();
		$db->types->test([
			'val' => ["\xB1\x31"],
		]);
	}

	public function testQueryWithInvalidTypeParameters(): void
	{
		$this->expectException(InvalidArgumentException::class);

		$db = $this->getDb();
		$obj = new stdClass();
		$obj->name = 'Death';
		$db->types->test([
			'val' => $obj,
		]);
	}

	public function testTemplateQuery(): void
	{
		$db = $this->getDb();

		$result = $db->members->joined(['year' => 1983])->first(fetchMode: PDO::FETCH_ASSOC);
		$this->assertCount(4, $result);

		$result = $db->members->joined([
			'year' => 1983,
			'interestedInNames' => true,
		])->first(fetchMode: PDO::FETCH_ASSOC);
		$this->assertCount(5, $result);
	}

	public function testPdoDriverIsHandedToTemplateQuery(): void
	{
		$db = $this->getDb();
		$result = $db->members->joined(['year' => 1983])->first();

		// The PDO driver is handed to the template
		$this->assertSame('sqlite', $result['driver']);
	}

	public function testTemplateQueryWithPositionalArgs(): void
	{
		$this->expectException(InvalidArgumentException::class);

		$db = $this->getDb();

		$db->members->joined(1983);
	}

	public function testTemplateQueryWithNoSqlArgs(): void
	{
		$db = $this->getDb();

		$result = $db->members->ordered(['order' => 'asc'])->all();
		$this->assertSame('Andy LaRocque', $result[0]['name']);

		$result = $db->members->ordered(['order' => 'desc'])->all();
		$this->assertSame('Terry Butler', $result[0]['name']);
	}

	public function testExpandScriptDirsQueryFromDefault(): void
	{
		$db = new Database($this->connection(additionalDirs: true));

		$result = $db->members->list()->all();
		$this->assertCount(self::NUMBER_OF_MEMBERS, $result);
	}

	public function testScriptInstance(): void
	{
		$db = $this->getDb();

		$byId = $db->members->byId;
		$this->assertSame('Bill Andrews', $byId(5)->one()['name']);
	}

	public function testQueryPrintingNamedParameters(): void
	{
		$db = $this->withEnv('QUMA_DEBUG', '1', $this->getDb(...));
		$result = null;
		$output = '';

		$this->withEnv('QUMA_DEBUG_PRINT', '1', static function () use ($db, &$result, &$output): void {
			ob_start();

			try {
				$result = $db->members->joined([
					'year' => 1997,
					'testPrinting' => true,
					'interestedInNames' => true,
				])->first();
			} finally {
				$buffer = ob_get_clean();
				$output = is_string($buffer) ? $buffer : '';
			}
		});

		$this->assertIsArray($result);
		$this->assertSame('Shannon Hamm', $result['name']);
		$this->assertStringContainsString('Emotions :year', $output);
		$this->assertStringContainsString('mantas, -- :year', $output);
		$this->assertStringContainsString("' :year", $output);
		$this->assertStringContainsString('Secret Face :year', $output);
		$this->assertStringContainsString('joined = 1997', $output);
	}

	public function testQueryPrintingPositionalParameters(): void
	{
		$db = $this->withEnv('QUMA_DEBUG', '1', $this->getDb(...));
		$result = null;
		$output = '';

		$this->withEnv('QUMA_DEBUG_PRINT', '1', static function () use ($db, &$result, &$output): void {
			ob_start();

			try {
				$result = $db->members->left(2001)->first();
			} finally {
				$buffer = ob_get_clean();
				$output = is_string($buffer) ? $buffer : '';
			}
		});

		$this->assertIsArray($result);
		$this->assertSame('Shannon Hamm', $result['name']);
		$this->assertStringContainsString('Emotions ?', $output);
		$this->assertStringContainsString('mantas, -- ?', $output);
		$this->assertStringContainsString("' ?", $output);
		$this->assertStringContainsString('Secret Face ?', $output);
		$this->assertStringContainsString('WHERE left = 2001', $output);
	}

	public function testExpandScriptDirsQueryFromExpanded(): void
	{
		$db = new Database($this->connection(additionalDirs: true));

		$result = $db->members->byName(['name' => 'Rick Rozz'])->one();
		$this->assertSame(2, $result['member']);
	}

	public function testExpandScriptDirsQueryFromExpandedNewNamespace(): void
	{
		$db = new Database($this->connection(additionalDirs: true));

		$result = $db->albums->list()->all();
		$this->assertCount(7, $result);
	}

	public function testFetchReturnsSuccessiveRows(): void
	{
		$db = new Database($this->connection());
		$query = $db->members->activeFromTo([
			'from' => 1990,
			'to' => 1995,
		]);

		$i = 0;
		$result = $query->fetch();

		while ($result) {
			$i++;
			$result = $query->fetch();
		}

		$this->assertSame(7, $i);
		$this->assertNull($query->fetch());
	}

	public function testLoadScriptThrowsWhenFileIsMissing(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not read SQL script');

		$db = new Database($this->connection());
		$handler = set_error_handler(static fn(): bool => true);

		try {
			$db->loadScript(sys_get_temp_dir() . '/quma-missing-script-' . uniqid() . '.sql', false);
		} finally {
			if ($handler !== null) {
				restore_error_handler();
			}
		}
	}

	public function testLoadTemplateScriptThrowsWhenFileIsMissing(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not read SQL script');

		$db = new Database($this->connection());
		$db->loadScript(sys_get_temp_dir() . '/quma-missing-script-' . uniqid() . '.tpql', true);
	}

	public function testDatabaseExecute(): void
	{
		$db = new Database($this->connection());
		$query = 'SELECT * FROM albums';

		$this->assertCount(7, $db->execute($query)->all());
	}

	public function testDatabaseExecuteWithArgs(): void
	{
		$db = new Database($this->connection());
		$queryQmark = 'SELECT name FROM members WHERE joined = ? AND left = ?';
		$queryNamed = 'SELECT name FROM members WHERE joined = :joined AND left = :left';

		$this->assertSame(
			'Sean Reinert',
			$db->execute($queryQmark, [1991, 1992])->first()['name'],
		);

		$this->assertSame(
			'Sean Reinert',
			$db->execute($queryQmark, 1991, 1992)->first()['name'],
		);

		$this->assertSame(
			'Sean Reinert',
			$db->execute($queryNamed, ['left' => 1992, 'joined' => 1991])->first()['name'],
		);
	}

	public function testScriptDirShadowingAndDriverSpecific(): void
	{
		$db = $this->getDb();

		// The query in the default dir uses positional parameters
		// and returns the field `left` additionally to `member` and `name`.
		$result = $db->members->byId(2)->one();
		$this->assertSame('Rick Rozz', $result['name']);
		$this->assertSame(1989, $result['left']);

		// The query in the sqlite specific dir uses named parameters
		// and additionally returns the field `joined` in contrast
		// to the default dir, which returns the field `left`.
		$db = $this->getDb(additionalDirs: true);
		// Named parameter queries also support positional arguments
		$result = $db->members->byId(3)->one();
		$this->assertSame('Chris Reifert', $result['name']);
		$this->assertSame(1986, $result['joined']);
		// Passed named args
		$result = $db->members->byId(['member' => 4])->one();
		$this->assertSame('Terry Butler', $result['name']);
		$this->assertSame(1987, $result['joined']);
	}

	public function testAccessingNonExistentNamespaceFolder(): void
	{
		$this->expectException(RuntimeException::class);

		$db = $this->getDb();
		$db->doesNotExist;
	}

	public function testAccessingNonExistentScriptQuery(): void
	{
		$this->expectException(RuntimeException::class);

		$db = $this->getDb();
		$db->members->doesNotExist;
	}

	#[DataProvider('invalidPathSegmentProvider')]
	public function testRejectsInvalidSqlFolderSegments(string $segment): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Invalid SQL folder name');

		$db = $this->getDb();
		$db->{$segment};
	}

	#[DataProvider('invalidPathSegmentProvider')]
	public function testRejectsInvalidSqlScriptSegments(string $segment): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Invalid SQL script name');

		$db = $this->getDb();
		$db->members->{$segment};
	}

	public static function invalidPathSegmentProvider(): array
	{
		return [
			[''],
			['.'],
			['..'],
			['../members'],
			['members/../members'],
			['members\\byId'],
			["members\0byId"],
		];
	}
}
