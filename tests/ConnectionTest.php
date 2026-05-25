<?php

declare(strict_types=1);

namespace Celemas\Quma\Tests;

use Celemas\Quma\Connection;
use Celemas\Quma\Delimiters;
use PDO;
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

		$this->assertSame($dsn, $conn->config->dsn);
		$this->assertSame('sqlite', $conn->config->driver);
		$this->assertSame(realpath($sql), realpath($conn->config->sql[0]));
		$this->assertSame(PDO::FETCH_ASSOC, $conn->config->pdo->fetchMode);
	}

	public function testPdoConfiguration(): void
	{
		$conn = new Connection($this->getDsn(), $this->getSqlDirs())
			->credentials('quma', 'secret')
			->options([PDO::ATTR_TIMEOUT => 2])
			->option(PDO::ATTR_PERSISTENT, true)
			->fetch(PDO::FETCH_ASSOC);

		$this->assertSame('quma', $conn->config->pdo->username);
		$this->assertSame('secret', $conn->config->pdo->password);
		$this->assertSame(
			[PDO::ATTR_TIMEOUT => 2, PDO::ATTR_PERSISTENT => true],
			$conn->config->pdo->options,
		);
		$this->assertSame(PDO::FETCH_ASSOC, $conn->config->pdo->fetchMode);
	}

	public function testPdoEffectiveOptionsMergeDefaultsAndRequiredOptions(): void
	{
		$conn = new Connection($this->getDsn(), $this->getSqlDirs())
			->options([
				PDO::ATTR_TIMEOUT => 2,
				PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
				PDO::ATTR_EMULATE_PREPARES => false,
			]);

		$effective = $conn->config->pdo->effectiveOptions();

		$this->assertSame(2, $effective[PDO::ATTR_TIMEOUT]);
		$this->assertFalse($effective[PDO::ATTR_EMULATE_PREPARES]);
		$this->assertSame(PDO::CURSOR_SCROLL, $effective[PDO::ATTR_CURSOR]);
		$this->assertSame(PDO::CASE_NATURAL, $effective[PDO::ATTR_CASE]);
		$this->assertSame(PDO::ERRMODE_EXCEPTION, $effective[PDO::ATTR_ERRMODE]);
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

		$sql = $conn->config->sql;
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

		$this->assertSame(
			'SELECT * FROM sqlite_nodes',
			$conn->config->placeholders?->compileSql('SELECT * FROM /*:prefix:*/nodes', 'query.sql'),
		);
	}

	public function testPlaceholdersAreSkippedWhenNotConfigured(): void
	{
		$conn = new Connection($this->getDsn(), TestCase::root() . 'sql/default');

		$source = 'SELECT * FROM [::prefix::]nodes';

		$this->assertNull($conn->config->placeholders);
		$this->assertSame(
			$source,
			$conn->config->placeholders?->compileSql($source, 'query.sql') ?? $source,
		);
	}

	public function testCustomPlaceholderDelimitersAreConfiguredWithPlaceholders(): void
	{
		$delimiters = new Delimiters('[[', ']]');
		$conn = new Connection($this->getDsn(), TestCase::root() . 'sql/default')
			->placeholders($delimiters, ['all' => ['prefix' => 'custom_']]);

		$this->assertSame(
			'SELECT * FROM custom_nodes',
			$conn->config->placeholders?->compileSql('SELECT * FROM [[prefix]]nodes', 'query.sql'),
		);
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

		$sql = $conn->config->sql;
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

		$sql = $conn->config->sql;
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

		$sql = $conn->config->sql;
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

		$sql = $conn->config->sql;
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
		$migrations = $conn->config->migrations;

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
		$migrations = $conn->config->migrations;

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
		$migrations = $conn->config->migrations;

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
		$migrations = $conn->config->migrations;

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
		$migrations = $conn->config->migrations;

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

		$migrations = $conn->config->migrations;
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

	public function testAddSqlWithEmptyConfigLeavesExistingDirs(): void
	{
		$conn = new Connection($this->getDsn(), TestCase::root() . 'sql/default');
		$conn->addSql([]);

		$this->assertCount(1, $conn->config->sql);
		$this->assertStringEndsWith('/default', $conn->config->sql[0]);
	}

	public function testSqlDirsSkipUnsupportedNestedListValues(): void
	{
		$conn = new Connection($this->getDsn(), [[123, TestCase::root() . 'sql/default']]);

		$this->assertCount(1, $conn->config->sql);
		$this->assertStringEndsWith('/default', $conn->config->sql[0]);
	}

	public function testDriverSpecificDirsSkipUnsupportedValues(): void
	{
		$direct = new Connection($this->getDsn(), ['sqlite' => 123]);
		$nested = new Connection($this->getDsn(), [
			'sqlite' => [123],
			'all' => TestCase::root() . 'sql/default',
		]);

		$this->assertSame([], $direct->config->sql);
		$this->assertCount(1, $nested->config->sql);
		$this->assertStringEndsWith('/default', $nested->config->sql[0]);
	}

	public function testNamespacedMigrationDirsSkipInvalidDirectoryValue(): void
	{
		$conn = new Connection($this->getDsn(), TestCase::root() . 'sql/default')
			->migrations([
				'valid' => TestCase::root() . 'migrations',
				'invalid' => 123,
			]);

		$this->assertArrayHasKey('valid', $conn->config->migrations);
		$this->assertArrayNotHasKey('invalid', $conn->config->migrations);
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

		$this->assertSame('migrations', $conn->config->migrationsTable);
		$this->assertSame('migration', $conn->config->migrationsColumnMigration);
		$this->assertSame('applied', $conn->config->migrationsColumnApplied);

		$conn->migrationTable('newmigrations')
			->migrationColumns('newmigration', 'newapplied');

		$this->assertSame('newmigrations', $conn->config->migrationsTable);
		$this->assertSame('newmigration', $conn->config->migrationsColumnMigration);
		$this->assertSame('newapplied', $conn->config->migrationsColumnApplied);
	}

	public function testConfigPropertiesRejectExternalMutation(): void
	{
		$this->expectException(\Error::class);

		$conn = new Connection($this->getDsn(), $this->getSqlDirs());
		$conn->config->migrationsTable = 'changed';
	}

	public function testPdoConfigPropertiesRejectExternalMutation(): void
	{
		$this->expectException(\Error::class);

		$conn = new Connection($this->getDsn(), $this->getSqlDirs());
		$conn->config->pdo->options[PDO::ATTR_TIMEOUT] = 2;
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
