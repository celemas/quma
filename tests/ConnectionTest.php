<?php

declare(strict_types=1);

namespace Celemas\Quma\Tests;

use Celemas\Quma\Config;
use Celemas\Quma\Connection;
use Celemas\Quma\Delimiters;
use PDO;
use ReflectionMethod;
use RuntimeException;
use ValueError;

/**
 * @internal
 */
class ConnectionTest extends TestCase
{
	public function testInitialization(): void
	{
		$dsn = $this->getDsn();
		$sql = $this->getSqlDirs();
		$conn = new Connection($dsn, $sql);

		$this->assertSame($dsn, $conn->dsn());
		$this->assertSame('sqlite', $conn->driver());
		$this->assertSame(realpath($sql), realpath($conn->sql()[0]));
		$this->assertSame(PDO::FETCH_ASSOC, $conn->fetchMode());
	}

	public function testPdoConfiguration(): void
	{
		$conn = new Connection($this->getDsn(), $this->getSqlDirs())
			->credentials('quma', 'secret')
			->options([PDO::ATTR_TIMEOUT => 2])
			->option(PDO::ATTR_PERSISTENT, true)
			->fetch(PDO::FETCH_ASSOC);

		$this->assertSame('quma', $conn->username());
		$this->assertSame('secret', $conn->password());
		$this->assertSame(
			[PDO::ATTR_TIMEOUT => 2, PDO::ATTR_PERSISTENT => true],
			$conn->pdoOptions(),
		);
		$this->assertSame(PDO::FETCH_ASSOC, $conn->fetchMode());
	}

	public function testDriverSpecificDir(): void
	{
		$conn = new Connection($this->getDsn(), [
			'all' => [
				TestCase::root() . 'sql/default',
				TestCase::root() . 'sql/more',
			],
			'sqlite' => TestCase::root() . 'sql/additional',
			'ignored' => TestCase::root() . 'sql/ignored',
		]);

		$sql = $conn->sql();
		$this->assertCount(3, $sql);
		// Driver specific must come first
		$this->assertStringEndsWith('/additional', $sql[0]);
		$this->assertStringEndsWith('/default', $sql[1]);
		$this->assertStringEndsWith('/more', $sql[2]);
	}

	public function testPlaceholderConfiguration(): void
	{
		$conn = new Connection(
			$this->getDsn(),
			TestCase::root() . 'sql/default',
		)->placeholders(Delimiters::comments(), [
			'all' => ['prefix' => 'all_'],
			'sqlite' => ['prefix' => 'sqlite_'],
		]);

		$this->assertEquals(Delimiters::comments(), $conn->placeholderDelimiters());
		$this->assertSame(['prefix' => 'sqlite_'], $conn->placeholderValues());
		$this->assertSame(
			'SELECT * FROM sqlite_nodes',
			$conn->applyPlaceholders('SELECT * FROM /*:prefix:*/nodes', 'query.sql'),
		);
	}

	public function testPlaceholdersAreSkippedWhenNotConfigured(): void
	{
		$conn = new Connection($this->getDsn(), TestCase::root() . 'sql/default');

		$this->assertNull($conn->placeholderDelimiters());
		$this->assertSame([], $conn->placeholderValues());
		$this->assertSame(
			'SELECT * FROM [::prefix::]nodes',
			$conn->applyPlaceholders('SELECT * FROM [::prefix::]nodes', 'query.sql'),
		);
	}

	public function testCustomPlaceholderDelimitersAreConfiguredWithPlaceholders(): void
	{
		$delimiters = new Delimiters('[[', ']]');
		$conn = new Connection($this->getDsn(), TestCase::root() . 'sql/default')
			->placeholders($delimiters, ['all' => ['prefix' => 'custom_']]);

		$this->assertSame($delimiters, $conn->placeholderDelimiters());
		$this->assertSame(
			'SELECT * FROM custom_nodes',
			$conn->applyPlaceholders('SELECT * FROM [[prefix]]nodes', 'query.sql'),
		);
	}

	public function testCacheDirConfiguration(): void
	{
		$conn = new Connection($this->getDsn(), TestCase::root() . 'sql/default');
		$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quma-cache-' . uniqid();
		mkdir($dir, 0o700);

		try {
			$this->assertNull($conn->cacheDir());
			$this->assertSame($conn, $conn->cache($dir));
			$this->assertSame(realpath($dir), $conn->cacheDir());
			$this->assertSame($conn, $conn->noCache());
			$this->assertNull($conn->cacheDir());
		} finally {
			rmdir($dir);
		}
	}

	public function testCacheDirRejectsMissingPath(): void
	{
		$this->expectException(ValueError::class);
		$this->expectExceptionMessage('Cache directory does not exist');

		$conn = new Connection($this->getDsn(), TestCase::root() . 'sql/default');
		$conn->cache(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quma-cache-missing-' . uniqid());
	}

	public function testCacheDirRejectsFilePath(): void
	{
		$this->expectException(ValueError::class);
		$this->expectExceptionMessage('Cache path is not a directory');

		$conn = new Connection($this->getDsn(), TestCase::root() . 'sql/default');
		$file = tempnam(sys_get_temp_dir(), 'quma-cache-file-');
		assert(is_string($file), 'Temporary cache file path must be available.');

		try {
			$conn->cache($file);
		} finally {
			unlink($file);
		}
	}

	public function testAddSqlDirsLater(): void
	{
		$conn = new Connection(
			$this->getDsn(),
			TestCase::root() . 'sql/default',
		);

		$conn->addSql([
			'sqlite' => TestCase::root() . 'sql/additional',
			'ignored' => TestCase::root() . 'sql/ignored',
		]);

		$sql = $conn->sql();
		$this->assertCount(2, $sql);
		// Driver specific must come first
		$this->assertStringEndsWith('/additional', $sql[0]);
		$this->assertStringEndsWith('/default', $sql[1]);
	}

	public function testMixedDirsFormat(): void
	{
		$conn = new Connection(
			$this->getDsn(),
			[
				[
					'all' => TestCase::root() . 'sql/default',
					'sqlite' => TestCase::root() . 'sql/additional',
					'ignored' => TestCase::root() . 'sql/ignored',
				],
				TestCase::root() . 'sql/additional/members',
			],
		);

		$sql = $conn->sql();
		$this->assertCount(3, $sql);
		$this->assertStringEndsWith('/members', $sql[0]);
		$this->assertStringEndsWith('/additional', $sql[1]);
		$this->assertStringEndsWith('/default', $sql[2]);
	}

	public function testNestedSqlDirsList(): void
	{
		$conn = new Connection(
			$this->getDsn(),
			[
				[
					TestCase::root() . 'sql/default',
					TestCase::root() . 'sql/more',
				],
			],
		);

		$sql = $conn->sql();
		$this->assertCount(2, $sql);
		$this->assertStringEndsWith('/more', $sql[0]);
		$this->assertStringEndsWith('/default', $sql[1]);
	}

	public function testDriverSpecificArrayDirs(): void
	{
		$conn = new Connection(
			$this->getDsn(),
			[
				'sqlite' => [
					TestCase::root() . 'sql/additional',
					TestCase::root() . 'sql/default',
				],
				'all' => [
					TestCase::root() . 'sql/more',
				],
			],
		);

		$sql = $conn->sql();
		$this->assertCount(3, $sql);
		$this->assertStringEndsWith('/additional', $sql[0]);
		$this->assertStringEndsWith('/default', $sql[1]);
		$this->assertStringEndsWith('/more', $sql[2]);
	}

	public function testMigrationDirectories(): void
	{
		$conn = new Connection(
			$this->getDsn(),
			TestCase::root() . 'sql/default',
		)->migrations([TestCase::root() . 'migrations', TestCase::root() . 'sql/additional']);
		$migrations = $conn->migrationDirs();

		$this->assertCount(2, $migrations);
		$this->assertStringEndsWith('/additional', $migrations[0]);
		$this->assertStringEndsWith('/migrations', $migrations[1]);
	}

	public function testNamespacedMigrationDirectories(): void
	{
		$conn = new Connection(
			$this->getDsn(),
			TestCase::root() . 'sql/default',
		)->migrations([
			'default' => [TestCase::root() . 'migrations', TestCase::root() . 'sql/default'],
			'install' => TestCase::root() . 'sql/additional',
		]);
		$migrations = $conn->migrationDirs();

		$this->assertCount(2, $migrations);
		$this->assertStringEndsWith('/migrations', $migrations['default'][0]);
		$this->assertStringEndsWith('/default', $migrations['default'][1]);
		$this->assertStringEndsWith('/additional', $migrations['install']);
	}

	public function testNamespacedMigrationDirectoriesWithEmptyNamespace(): void
	{
		$conn = new Connection(
			$this->getDsn(),
			TestCase::root() . 'sql/default',
		)->migrations([
			'' => TestCase::root() . 'migrations',
			'valid' => [
				[
					'all' => TestCase::root() . 'sql/default',
				],
			],
		]);
		$migrations = $conn->migrationDirs();

		$this->assertCount(1, $migrations);
		$this->assertArrayHasKey('valid', $migrations);
		$this->assertStringEndsWith('/default', $migrations['valid'][0]);
	}

	public function testNamespacedMigrationDirectoriesWithNestedList(): void
	{
		$conn = new Connection(
			$this->getDsn(),
			TestCase::root() . 'sql/default',
		)->migrations([
			'valid' => [
				[
					TestCase::root() . 'migrations',
					TestCase::root() . 'sql/default',
				],
			],
		]);
		$migrations = $conn->migrationDirs();

		$this->assertCount(1, $migrations);
		$this->assertArrayHasKey('valid', $migrations);
		$this->assertStringEndsWith('/migrations', $migrations['valid'][0]);
		$this->assertStringEndsWith('/default', $migrations['valid'][1]);
	}

	public function testAddMigrationDirectoriesLater(): void
	{
		$conn = new Connection(
			$this->getDsn(),
			TestCase::root() . 'sql/default',
		);
		$conn->addMigration(TestCase::root() . 'migrations');
		$conn->addMigration(TestCase::root() . 'sql/additional');
		$migrations = $conn->migrationDirs();

		$this->assertCount(2, $migrations);
		$this->assertStringEndsWith('/additional', $migrations[0]);
		$this->assertStringEndsWith('/migrations', $migrations[1]);
	}

	public function testAddMigrationDirRejectsNamespacedMigrations(): void
	{
		$this->expectException(ValueError::class);
		$this->expectExceptionMessage(
			'Cannot add a flat migration directory when migrations are namespaced',
		);

		$conn = new Connection(
			$this->getDsn(),
			TestCase::root() . 'sql/default',
		)->migrations([
			'default' => [TestCase::root() . 'migrations'],
		]);

		$conn->addMigration(TestCase::root() . 'sql/additional');
	}

	public function testMigrationNamespaceAddsNamespacedMigrations(): void
	{
		$conn = new Connection($this->getDsn(), TestCase::root() . 'sql/default')
			->migrationNamespace('default', [TestCase::root() . 'migrations'])
			->migrationNamespace('install', TestCase::root() . 'sql/additional');

		$migrations = $conn->migrationDirs();
		$this->assertCount(2, $migrations);
		$this->assertStringEndsWith('/migrations', $migrations['default'][0]);
		$this->assertStringEndsWith('/additional', $migrations['install']);
	}

	public function testMigrationNamespaceRejectsEmptyNamespace(): void
	{
		$this->expectException(ValueError::class);
		$this->expectExceptionMessage('Migration namespace must not be empty');

		new Connection($this->getDsn(), TestCase::root() . 'sql/default')
			->migrationNamespace('', TestCase::root() . 'migrations');
	}

	public function testMigrationNamespaceRejectsFlatMigrations(): void
	{
		$this->expectException(ValueError::class);
		$this->expectExceptionMessage('Cannot add a namespaced migration directory');

		new Connection($this->getDsn(), TestCase::root() . 'sql/default')
			->migrations(TestCase::root() . 'migrations')
			->migrationNamespace('install', TestCase::root() . 'sql/additional');
	}

	public function testReadFlatDirsReturnsEmptyArrayForEmptyConfig(): void
	{
		$config = new Config($this->getDsn(), TestCase::root() . 'sql/default');
		$method = new ReflectionMethod(Config::class, 'readFlatDirs');

		$this->assertSame([], $method->invoke($config, [], false));
	}

	public function testReadFlatDirsCanPreserveConfiguredOrder(): void
	{
		$config = new Config($this->getDsn(), TestCase::root() . 'sql/default');
		$method = new ReflectionMethod(Config::class, 'readFlatDirs');

		$dirs = $method->invoke(
			$config,
			[
				TestCase::root() . 'sql/default',
				[TestCase::root() . 'sql/more'],
				['sqlite' => TestCase::root() . 'sql/additional'],
			],
			true,
		);

		$this->assertCount(3, $dirs);
		$this->assertStringEndsWith('/default', $dirs[0]);
		$this->assertStringEndsWith('/more', $dirs[1]);
		$this->assertStringEndsWith('/additional', $dirs[2]);
	}

	public function testReadFlatDirsSkipsUnsupportedNestedListValues(): void
	{
		$config = new Config($this->getDsn(), TestCase::root() . 'sql/default');
		$method = new ReflectionMethod(Config::class, 'readFlatDirs');

		$dirs = $method->invoke($config, [[123, TestCase::root() . 'sql/default']], false);

		$this->assertCount(1, $dirs);
		$this->assertStringEndsWith('/default', $dirs[0]);
	}

	public function testReadDirsEntrySkipsUnsupportedValues(): void
	{
		$config = new Config($this->getDsn(), TestCase::root() . 'sql/default');
		$method = new ReflectionMethod(Config::class, 'readDirsEntry');

		$this->assertSame([], $method->invoke($config, 123));
		$this->assertSame([], $method->invoke($config, [123]));
	}

	public function testReadNamespacedDirsSkipsInvalidNamespaceDirectoryValue(): void
	{
		$config = new Config($this->getDsn(), TestCase::root() . 'sql/default');
		$method = new ReflectionMethod(Config::class, 'readNamespacedDirs');

		$namespacedDirs = $method->invoke($config, [
			'valid' => TestCase::root() . 'migrations',
			'invalid' => 123,
		]);

		$this->assertArrayHasKey('valid', $namespacedDirs);
		$this->assertArrayNotHasKey('invalid', $namespacedDirs);
	}

	public function testUnsupportedDsn(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('driver not supported');

		new Connection('notsupported:host=localhost;dbname=chuck', $this->getSqlDirs());
	}

	public function testMigrationTableSetting(): void
	{
		$conn = new Connection($this->getDsn(), $this->getSqlDirs());

		$this->assertSame('migrations', $conn->migrationsTable());
		$this->assertSame('migration', $conn->migrationsColumnMigration());
		$this->assertSame('applied', $conn->migrationsColumnApplied());

		$conn->migrationTable('newmigrations')
			->migrationColumns('newmigration', 'newapplied');

		$this->assertSame('newmigrations', $conn->migrationsTable());
		$this->assertSame('newmigration', $conn->migrationsColumnMigration());
		$this->assertSame('newapplied', $conn->migrationsColumnApplied());
	}

	public function testWrongMigrationsTableName(): void
	{
		$this->expectException(ValueError::class);
		$this->expectExceptionMessage('Invalid migrations table name');

		$conn = new Connection($this->getDsn(), $this->getSqlDirs());
		$conn->migrationTable('new migrations');
	}

	public function testWrongMigrationColumnName(): void
	{
		$this->expectException(ValueError::class);
		$this->expectExceptionMessage('Invalid migrations table column name');

		$conn = new Connection($this->getDsn(), $this->getSqlDirs());
		$conn->migrationColumns('new migration');
	}

	public function testWrongAppliedColumnName(): void
	{
		$this->expectException(ValueError::class);
		$this->expectExceptionMessage('Invalid migrations table column name');

		$conn = new Connection($this->getDsn(), $this->getSqlDirs());
		$conn->migrationColumns('migration', 'new migration');
	}
}
