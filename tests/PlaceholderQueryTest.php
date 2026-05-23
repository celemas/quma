<?php

declare(strict_types=1);

namespace Celemas\Quma\Tests;

use Celemas\Quma\Connection;
use Celemas\Quma\Database;
use Celemas\Quma\Delimiters;
use PDO;
use RuntimeException;

/**
 * @internal
 */
class PlaceholderQueryTest extends TestCase
{
	/** @var list<string> */
	private array $tempDirs = [];

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
		self::createTestDb();
	}

	protected function tearDown(): void
	{
		foreach ($this->tempDirs as $dir) {
			$this->removeDir($dir);
		}

		$this->tempDirs = [];
		parent::tearDown();
	}

	public function testSqlFileUsesPlaceholdersAndRuntimeParameters(): void
	{
		$dir = $this->createSqlDir();
		file_put_contents(
			$dir . '/music/byMember.sql',
			'SELECT name FROM [::table::] WHERE member = :member;',
		);

		$db = new Database(new Connection($this->getDsn(), $dir)->placeholders(Delimiters::brackets(), [
			'all' => ['table' => 'albums'],
			'sqlite' => ['table' => 'members'],
		]));

		$result = $db->music->byMember(['member' => 1])->one(fetchMode: PDO::FETCH_ASSOC);

		$this->assertSame('Chuck Schuldiner', $result['name']);
	}

	public function testSqlFileUsesCustomDelimiters(): void
	{
		$dir = $this->createSqlDir();
		file_put_contents(
			$dir . '/music/byMemberCustom.sql',
			'SELECT name FROM [[table]] WHERE member = :member;',
		);

		$db = new Database(
			new Connection($this->getDsn(), $dir)
				->placeholders(new Delimiters('[[', ']]'), ['all' => ['table' => 'members']]),
		);

		$result = $db->music->byMemberCustom(['member' => 1])->one(fetchMode: PDO::FETCH_ASSOC);

		$this->assertSame('Chuck Schuldiner', $result['name']);
	}

	public function testDebugTranslatedWritesRuntimeQueryFile(): void
	{
		$dir = $this->createSqlDir();
		$debugDir = $this->createTempDir('quma-translated-');
		file_put_contents(
			$dir . '/music/debug.sql',
			'SELECT name FROM [::table::] WHERE member = :member;',
		);

		$db = $this->debugDb(
			new Connection($this->getDsn(), $dir)->placeholders(Delimiters::brackets(), ['all' => [
				'table' => 'members',
			]]),
		);

		$this->withEnv('QUMA_DEBUG_TRANSLATED', $debugDir, static function () use ($db): void {
			$db->music->debug(['member' => 1])->one(fetchMode: PDO::FETCH_ASSOC);
		});

		$files = glob($debugDir . '/*/????--music--debug.sql');
		$this->assertIsArray($files);
		$this->assertCount(1, $files);
		$this->assertMatchesRegularExpression(
			'/\/\d{8}-\d{6}-\d{6}--cli--[a-f0-9]{8}\/\d{4}--music--debug\.sql$/',
			$files[0],
		);
		$this->assertSame(
			'SELECT name FROM members WHERE member = :member;',
			file_get_contents($files[0]),
		);
	}

	public function testDebugTranslatedRejectsMissingDirectory(): void
	{
		$dir = $this->createSqlDir();
		$debugDir = $this->createTempDir('quma-missing-debug-') . '/missing';
		file_put_contents(
			$dir . '/music/debug.sql',
			'SELECT name FROM members WHERE member = :member;',
		);

		$db = $this->debugDb(new Connection($this->getDsn(), $dir));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage(
			'Quma debug directory does not exist for QUMA_DEBUG_TRANSLATED: ' . $debugDir,
		);

		$this->withEnv('QUMA_DEBUG_TRANSLATED', $debugDir, static function () use ($db): void {
			$db->music->debug(['member' => 1])->one(fetchMode: PDO::FETCH_ASSOC);
		});
	}

	public function testDebugWithoutOutputChannelsIsNoOp(): void
	{
		$db = $this->debugDb($this->connection());

		$this->withEnv('QUMA_DEBUG_PRINT', null, function () use ($db): void {
			$this->withEnv('QUMA_DEBUG_TRANSLATED', null, function () use ($db): void {
				$this->withEnv('QUMA_DEBUG_INTERPOLATED', null, static function () use ($db): void {
					$result = $db->execute('SELECT name FROM members WHERE member = ?', 1)
						->one(fetchMode: PDO::FETCH_ASSOC);

					self::assertSame('Chuck Schuldiner', $result['name']);
				});
			});
		});
	}

	public function testDebugTranslatedWritesRenderedTemplatePerInvocation(): void
	{
		$dir = $this->createSqlDir();
		$debugDir = $this->createTempDir('quma-translated-');
		file_put_contents(
			$dir . '/music/dynamic.tpql',
			<<<'TPQL'
				SELECT name
				FROM members
				WHERE member = :member
				<?php if (($joinedAfter ?? null) !== null) : ?>
				AND joined > :joinedAfter
				<?php endif ?>
				TPQL,
		);

		$db = $this->debugDb(new Connection($this->getDsn(), $dir));

		$this->withEnv('QUMA_DEBUG_TRANSLATED', $debugDir, static function () use ($db): void {
			$db->music->dynamic(['member' => 1])->one(fetchMode: PDO::FETCH_ASSOC);
			$db->music->dynamic(['member' => 1, 'joinedAfter' => 1980])->one(fetchMode: PDO::FETCH_ASSOC);
		});

		$files = glob($debugDir . '/*/????--music--dynamic.sql');
		$this->assertIsArray($files);
		sort($files);
		$this->assertCount(2, $files);
		$this->assertStringNotContainsString(
			'AND joined > :joinedAfter',
			(string) file_get_contents($files[0]),
		);
		$this->assertStringContainsString(
			'AND joined > :joinedAfter',
			(string) file_get_contents($files[1]),
		);
	}

	public function testDebugSessionUsesHttpRequestInfo(): void
	{
		$dir = $this->createSqlDir();
		$debugDir = $this->createTempDir('quma-http-debug-');
		file_put_contents(
			$dir . '/music/debug.sql',
			'SELECT name FROM members WHERE member = :member;',
		);

		$db = $this->debugDb(new Connection($this->getDsn(), $dir));

		$this->withEnv('QUMA_DEBUG_TRANSLATED', $debugDir, function () use ($db): void {
			$this->withServer(
				[
					'REQUEST_METHOD' => 'GET',
					'REQUEST_URI' => '/admin/users?token=secret',
					'REQUEST_TIME_FLOAT' => '1800000000.123456',
				],
				static function () use ($db): void {
					$db->music->debug(['member' => 1])->one(fetchMode: PDO::FETCH_ASSOC);
				},
			);
			$this->withServer(
				[
					'REQUEST_METHOD' => 'GET',
					'REQUEST_URI' => '/admin/users?token=secret',
					'REQUEST_TIME_FLOAT' => '1800000001.654321',
				],
				static function () use ($db): void {
					$db->music->debug(['member' => 1])->one(fetchMode: PDO::FETCH_ASSOC);
				},
			);
		});

		$files = glob($debugDir . '/*--GET--admin-users--*/0001--music--debug.sql');
		$this->assertIsArray($files);
		sort($files);
		$this->assertCount(2, $files);
		$this->assertStringNotContainsString('secret', $files[0]);
		$this->assertStringNotContainsString('secret', $files[1]);
	}

	public function testDebugSessionCanBeOverriddenForAdHocQueries(): void
	{
		$debugDir = $this->createTempDir('quma-session-debug-');
		$db = $this->debugDb($this->connection());

		$this->withEnv('QUMA_DEBUG_TRANSLATED', $debugDir, function () use ($db): void {
			$this->withEnv('QUMA_DEBUG_SESSION', 'manual/session id!', static function () use ($db): void {
				$db->execute('SELECT name FROM members WHERE member = ?', 1)->one(fetchMode: PDO::FETCH_ASSOC);
			});
		});

		$files = glob($debugDir . '/manual_session_id_/????--execute.sql');
		$this->assertIsArray($files);
		$this->assertCount(1, $files);
		$this->assertSame(
			'SELECT name FROM members WHERE member = ?',
			file_get_contents($files[0]),
		);
	}

	public function testDebugSessionUsesRootHttpRequestInfo(): void
	{
		$dir = $this->createSqlDir();
		$debugDir = $this->createTempDir('quma-root-http-debug-');
		file_put_contents(
			$dir . '/music/debug.sql',
			'SELECT name FROM members WHERE member = :member;',
		);

		$db = $this->debugDb(new Connection($this->getDsn(), $dir));

		$this->withEnv('QUMA_DEBUG_TRANSLATED', $debugDir, function () use ($db): void {
			$this->withServer(
				[
					'REQUEST_METHOD' => 'POST',
					'REQUEST_URI' => '/',
					'REQUEST_TIME_FLOAT' => '1800000002.000000',
				],
				static function () use ($db): void {
					$db->music->debug(['member' => 1])->one(fetchMode: PDO::FETCH_ASSOC);
				},
			);
		});

		$files = glob($debugDir . '/*--POST--root--*/0001--music--debug.sql');
		$this->assertIsArray($files);
		$this->assertCount(1, $files);
	}

	public function testDebugSessionUsesRequestTimeWhenFloatIsUnavailable(): void
	{
		$dir = $this->createSqlDir();
		$debugDir = $this->createTempDir('quma-request-time-debug-');
		file_put_contents(
			$dir . '/music/debug.sql',
			'SELECT name FROM members WHERE member = :member;',
		);

		$db = $this->debugDb(new Connection($this->getDsn(), $dir));

		$this->withEnv('QUMA_DEBUG_TRANSLATED', $debugDir, function () use ($db): void {
			$this->withServer(
				[
					'REQUEST_METHOD' => 'GET',
					'REQUEST_URI' => '/legacy',
					'REQUEST_TIME_FLOAT' => null,
					'REQUEST_TIME' => 1_800_000_000,
				],
				static function () use ($db): void {
					$db->music->debug(['member' => 1])->one(fetchMode: PDO::FETCH_ASSOC);
				},
			);
		});

		$files = glob($debugDir . '/????????-??????-000000--GET--legacy--*/0001--music--debug.sql');
		$this->assertIsArray($files);
		$this->assertCount(1, $files);
	}

	public function testDebugSessionFallsBackToCurrentCliTime(): void
	{
		$dir = $this->createSqlDir();
		$debugDir = $this->createTempDir('quma-cli-time-debug-');
		file_put_contents(
			$dir . '/music/debug.sql',
			'SELECT name FROM members WHERE member = :member;',
		);

		$db = $this->debugDb(new Connection($this->getDsn(), $dir));

		$this->withEnv('QUMA_DEBUG_TRANSLATED', $debugDir, function () use ($db): void {
			$this->withServer(
				[
					'REQUEST_METHOD' => null,
					'REQUEST_URI' => null,
					'REQUEST_TIME_FLOAT' => null,
					'REQUEST_TIME' => null,
					'SCRIPT_NAME' => null,
					'PHP_SELF' => null,
				],
				static function () use ($db): void {
					$db->music->debug(['member' => 1])->one(fetchMode: PDO::FETCH_ASSOC);
				},
			);
		});

		$files = glob($debugDir . '/????????-??????-??????--cli--*/????--music--debug.sql');
		$this->assertIsArray($files);
		$this->assertCount(1, $files);
	}

	public function testDebugInterpolatedWritesRuntimeQueryFile(): void
	{
		$dir = $this->createSqlDir();
		$debugDir = $this->createTempDir('quma-interpolated-');
		file_put_contents(
			$dir . '/music/debug.sql',
			'SELECT name FROM members WHERE member = :member;',
		);

		$db = $this->debugDb(new Connection($this->getDsn(), $dir));

		$this->withEnv('QUMA_DEBUG_INTERPOLATED', $debugDir, static function () use ($db): void {
			$db->music->debug(['member' => 1])->one(fetchMode: PDO::FETCH_ASSOC);
		});

		$files = glob($debugDir . '/*/????--music--debug.sql');
		$this->assertIsArray($files);
		$this->assertCount(1, $files);
		$this->assertMatchesRegularExpression(
			'/\/\d{8}-\d{6}-\d{6}--cli--[a-f0-9]{8}\/\d{4}--music--debug\.sql$/',
			$files[0],
		);
		$this->assertStringContainsString(
			'SELECT name FROM members WHERE member = 1;',
			(string) file_get_contents($files[0]),
		);
	}

	public function testSqlFilesAreCompiledOncePerDatabaseInstance(): void
	{
		$dir = $this->createSqlDir();
		$file = $dir . '/music/cached.sql';
		file_put_contents(
			$file,
			"SELECT 'before' AS value FROM [::table::] LIMIT 1;",
		);

		$db = new Database(
			new Connection($this->getDsn(), $dir)->placeholders(Delimiters::brackets(), ['all' => [
				'table' => 'members',
			]]),
		);

		$this->assertSame('before', $db->music->cached()->one(fetchMode: PDO::FETCH_ASSOC)['value']);

		file_put_contents(
			$file,
			"SELECT 'after' AS value FROM [::table::] LIMIT 1;",
		);

		$this->assertSame('before', $db->music->cached()->one(fetchMode: PDO::FETCH_ASSOC)['value']);
	}

	public function testTemplateFileUsesPlaceholdersAfterRendering(): void
	{
		$dir = $this->createSqlDir();
		file_put_contents(
			$dir . '/music/dynamic.tpql',
			<<<'TPQL'
				SELECT name
				FROM [::table::]
				WHERE member = :member
				<?php if (($joinedAfter ?? null) !== null) : ?>
				AND joined > :joinedAfter
				<?php endif ?>
				TPQL,
		);

		$db = new Database(
			new Connection($this->getDsn(), $dir)->placeholders(Delimiters::brackets(), ['all' => [
				'table' => 'members',
			]]),
		);

		$result = $db->music->dynamic([
			'member' => 1,
			'joinedAfter' => 1980,
		])->one(fetchMode: PDO::FETCH_ASSOC);

		$this->assertSame('Chuck Schuldiner', $result['name']);
	}

	public function testTemplateFileUsesCustomDelimitersAfterRendering(): void
	{
		$dir = $this->createSqlDir();
		file_put_contents(
			$dir . '/music/custom.tpql',
			<<<'TPQL'
				SELECT name
				FROM [[table]]
				WHERE member = :member
				TPQL,
		);

		$db = new Database(
			new Connection($this->getDsn(), $dir)
				->placeholders(new Delimiters('[[', ']]'), ['all' => ['table' => 'members']]),
		);

		$result = $db->music->custom(['member' => 1])->one(fetchMode: PDO::FETCH_ASSOC);

		$this->assertSame('Chuck Schuldiner', $result['name']);
	}

	public function testTemplateQueryDoesNotWriteConfiguredCacheFiles(): void
	{
		$dir = $this->createSqlDir();
		$cacheDir = $this->createTempDir('quma-cache-');
		file_put_contents(
			$dir . '/music/cached.tpql',
			"SELECT '[::value::]' AS value;",
		);

		$conn = new Connection($this->getDsn(), $dir)
			->placeholders(Delimiters::brackets(), ['all' => ['value' => 'cached']])
			->cache($cacheDir);
		$db = new Database($conn);

		$this->assertSame(
			'cached',
			$db->music->cached(['unused' => true])->one(fetchMode: PDO::FETCH_ASSOC)['value'],
		);
		$this->assertSame([], glob($cacheDir . '/tpql-*.php'));

		$this->assertSame(
			'cached',
			new Database($conn)->music->cached([
				'unused' => true,
			])->one(fetchMode: PDO::FETCH_ASSOC)['value'],
		);
		$this->assertSame([], glob($cacheDir . '/tpql-*.php'));
	}

	public function testTemplateGeneratedPlaceholdersAreSupported(): void
	{
		$dir = $this->createSqlDir();
		file_put_contents(
			$dir . '/music/generated.tpql',
			"SELECT name FROM <?= '[::table::]' ?> WHERE member = :member;",
		);

		$db = new Database(
			new Connection($this->getDsn(), $dir)->placeholders(Delimiters::brackets(), ['all' => [
				'table' => 'members',
			]]),
		);

		$result = $db->music->generated(['member' => 1])->one(fetchMode: PDO::FETCH_ASSOC);

		$this->assertSame('Chuck Schuldiner', $result['name']);
	}

	public function testTemplateGeneratedCustomPlaceholdersAreSupported(): void
	{
		$dir = $this->createSqlDir();
		file_put_contents(
			$dir . '/music/generated-custom.tpql',
			"SELECT name FROM <?= '[[table]]' ?> WHERE member = :member;",
		);

		$db = new Database(
			new Connection($this->getDsn(), $dir)
				->placeholders(new Delimiters('[[', ']]'), ['all' => ['table' => 'members']]),
		);

		$result = $db->music->{'generated-custom'}(['member' => 1])->one(fetchMode: PDO::FETCH_ASSOC);

		$this->assertSame('Chuck Schuldiner', $result['name']);
	}

	public function testTemplateUnknownPlaceholderInInactiveBranchIsIgnored(): void
	{
		$dir = $this->createSqlDir();
		file_put_contents(
			$dir . '/music/branch.tpql',
			<<<'TPQL'
				<?php if (($includeMissing ?? false) === true) : ?>
				SELECT name FROM [::missing::] WHERE member = :member;
				<?php else : ?>
				SELECT name FROM [::table::] WHERE member = :member;
				<?php endif ?>
				TPQL,
		);

		$db = new Database(
			new Connection($this->getDsn(), $dir)->placeholders(Delimiters::brackets(), ['all' => [
				'table' => 'members',
			]]),
		);

		$result = $db->music->branch(['member' => 1])->one(fetchMode: PDO::FETCH_ASSOC);

		$this->assertSame('Chuck Schuldiner', $result['name']);
	}

	public function testTemplateUnknownPlaceholderInActiveBranchThrows(): void
	{
		$dir = $this->createSqlDir();
		file_put_contents(
			$dir . '/music/branch-bad.tpql',
			<<<'TPQL'
				<?php if (($includeMissing ?? false) === true) : ?>
				SELECT name FROM [::missing::] WHERE member = :member;
				<?php else : ?>
				SELECT name FROM [::table::] WHERE member = :member;
				<?php endif ?>
				TPQL,
		);

		$db = new Database(
			new Connection($this->getDsn(), $dir)->placeholders(Delimiters::brackets(), ['all' => [
				'table' => 'members',
			]]),
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Unknown static placeholder [::missing::]');

		$db->music->{'branch-bad'}([
			'member' => 1,
			'includeMissing' => true,
		])->one(fetchMode: PDO::FETCH_ASSOC);
	}

	private function debugDb(Connection $conn): Database
	{
		return $this->withEnv('QUMA_DEBUG', '1', static fn(): Database => new Database($conn));
	}

	private function createSqlDir(): string
	{
		$dir = $this->createTempDir('quma-sql-');
		mkdir($dir . '/music', 0o700, true);

		return $dir;
	}

	private function createTempDir(string $prefix): string
	{
		$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . uniqid();
		mkdir($dir, 0o700);
		$this->tempDirs[] = $dir;

		return $dir;
	}

	private function removeDir(string $dir): void
	{
		$files = glob($dir . '/*');

		if (is_array($files)) {
			foreach ($files as $file) {
				if (is_file($file)) {
					unlink($file);

					continue;
				}

				if (is_dir($file)) {
					$this->removeDir($file);
				}
			}
		}

		rmdir($dir);
	}
}
