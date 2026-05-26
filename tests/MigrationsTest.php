<?php

/**
 * Migration testing is hard.
 *
 * Some of these tests depend on each other and the order
 * in which they are executed. Reorganize with care.
 *
 * Running a single test with '->only()' might be impossible.
 */

declare(strict_types=1);

namespace Celemas\Quma\Tests;

use Celemas\Cli\Runner;
use Celemas\Quma\Connection;
use Celemas\Quma\Contract\Migration as MigrationContract;
use Celemas\Quma\Database;
use Celemas\Quma\Delimiters;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Depends;
use RuntimeException;
use ValueError;

/**
 * @internal
 */
class MigrationsTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		// Remove remnants of previous runs
		$migrationsDir = TestCase::root() . '/migrations/';
		array_map('unlink', glob("{$migrationsDir}*test-migration*"));

		TestCase::cleanupTestDbs();
	}

	public function testCreateMigrationsTableSuccess(): void
	{
		$_SERVER['argv'] = ['run', 'migrations', '--apply'];

		ob_start();
		$result = new Runner($this->commands())->run();
		$content = ob_get_contents();
		ob_end_clean();

		$this->assertSame(0, $result);
		$this->assertStringContainsString("Created table 'migrations'", $content);
	}

	public function testWrongConnection(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/doesnotexist/');

		$_SERVER['argv'] = ['run', 'create-migrations-table', '--conn', 'doesnotexist'];

		new Runner($this->commands(multipleConnections: true))->run();
	}

	public function testRunMigrationsCreatesMetadataTableOnSelectedConnection(): void
	{
		$_SERVER['argv'] = ['run', 'migrations', '--conn', 'second', '--apply'];

		ob_start();
		$result = new Runner($this->commands(multipleConnections: true))->run();
		$content = ob_get_contents();
		ob_end_clean();

		$firstDb = new Database($this->connection());
		$secondDb = new Database($this->connection($this->getDsn(self::getSqliteDbPath2())));
		$firstTable = $firstDb->execute(
			"SELECT count(*) AS available FROM sqlite_master WHERE type='table' AND name='migrations';",
		)->one(fetchMode: PDO::FETCH_ASSOC);
		$secondTable = $secondDb->execute(
			"SELECT count(*) AS available FROM sqlite_master WHERE type='table' AND name='migrations';",
		)->one(fetchMode: PDO::FETCH_ASSOC);

		$this->assertSame(0, $result);
		$this->assertStringContainsString("Created table 'migrations'", $content);
		$this->assertSame(0, (int) ($firstTable['available'] ?? 0));
		$this->assertSame(1, (int) ($secondTable['available'] ?? 0));
	}

	public function testRunMigrationsNoMigrationsDirectoriesDefined(): void
	{
		$_SERVER['argv'] = ['run', 'migrations', '--apply'];

		ob_start();
		$result = new Runner($this->commands(migrations: []))->run();
		$content = ob_get_contents();
		ob_end_clean();

		$this->assertSame(1, $result);
		$this->assertStringContainsString('No migration directories defined', $content);
	}

	public function testRunMigrationsRejectsApplyAndTestRunTogether(): void
	{
		$_SERVER['argv'] = ['run', 'migrations', '--apply', '--test-run', '--yes'];

		ob_start();
		$result = new Runner($this->commands())->run();
		$content = ob_get_contents();
		ob_end_clean();

		$this->assertSame(1, $result);
		$this->assertStringContainsString(
			'Options --apply and --test-run cannot be used together',
			$content,
		);
	}

	#[DataProvider('transactionConnectionProvider')]
	public function testRunMigrationsPlansWithoutApply(string $dsn): void
	{
		$_SERVER['argv'] = ['run', 'migrations'];
		$driver = strtok($dsn, ':');

		ob_start();
		$result = new Runner($this->commands(dsn: $dsn))->run();
		$content = ob_get_contents();
		ob_end_clean();

		$this->assertSame(0, $result);
		$this->assertStringContainsString('Plan only', $content);
		$this->assertStringContainsString('Would apply 6 migrations', $content);
		$this->assertStringContainsString('000000-000000-migration.sql', $content);
		$this->assertStringContainsString('000000-000001-migration.php', $content);
		$this->assertStringContainsString('000000-000002-migration.tpql', $content);
		$this->assertStringContainsString("000000-000005-migration-[{$driver}].sql", $content);
		$this->assertStringContainsString('No migrations were executed', $content);
		$this->assertStringContainsString('--test-run --yes', $content);
		$this->assertStringNotContainsString('successfully applied', $content);
	}

	public function testRunMigrationsPlanReportsNoPendingMigrations(): void
	{
		$dir = $this->createMigrationDir('plan-no-pending');
		file_put_contents(
			$dir . '/000001-plan-no-pending.sql',
			'CREATE TABLE plan_no_pending (id integer);',
		);
		$conn = $this->connection(migrations: $dir);

		try {
			$_SERVER['argv'] = ['run', 'migrations', '--apply'];

			ob_start();
			$applyResult = new Runner(\Celemas\Quma\Commands::get($conn))->run();
			ob_end_clean();

			$_SERVER['argv'] = ['run', 'migrations'];

			ob_start();
			$planResult = new Runner(\Celemas\Quma\Commands::get($conn))->run();
			$content = ob_get_contents();
			ob_end_clean();

			$this->assertSame(0, $applyResult);
			$this->assertSame(0, $planResult);
			$this->assertStringContainsString('Plan only', $content);
			$this->assertStringContainsString('No pending migrations', $content);
			$this->assertStringNotContainsString('Would apply', $content);
		} finally {
			$this->removeMigrationDir($dir);
		}
	}

	public function testRunMigrationsTestRunRequiresYesForPendingMigrations(): void
	{
		$_SERVER['argv'] = ['run', 'migrations', '--test-run'];

		ob_start();
		$result = new Runner($this->commands())->run();
		$content = ob_get_contents();
		ob_end_clean();

		$this->assertSame(1, $result);
		$this->assertStringContainsString('--test-run executes migrations', $content);
		$this->assertStringContainsString(
			'Use --yes to confirm test-run execution in non-interactive shells',
			$content,
		);
		$this->assertStringNotContainsString('successfully applied', $content);
	}

	#[DataProvider('transactionConnectionProvider')]
	public function testRunMigrationsTestRunSuccess(string $dsn): void
	{
		$_SERVER['argv'] = ['run', 'migrations', '--test-run', '--yes'];
		$driver = strtok($dsn, ':');

		ob_start();
		$result = new Runner($this->commands(dsn: $dsn))->run();
		$content = ob_get_contents();
		ob_end_clean();

		$this->assertSame(0, $result);
		$this->assertStringContainsString('--test-run executes migrations', $content);
		$this->assertMatchesRegularExpression('/000000-000000-migration.sql[^\n]*?success/', $content);
		$this->assertMatchesRegularExpression('/000000-000001-migration.php[^\n]*?success/', $content);
		$this->assertMatchesRegularExpression('/000000-000002-migration.tpql[^\n]*?success/', $content);
		$this->assertMatchesRegularExpression(
			'/000000-000005-migration-\[' . $driver . '\].sql[^\n]*?success/',
			$content,
		);
		$this->assertStringContainsString('Rolled back 4 migrations', $content);
	}

	public function testRunMigrationsTestRunSkipsConfirmationWithoutPendingMigrations(): void
	{
		$dir = $this->createMigrationDir('test-run-no-pending');
		file_put_contents(
			$dir . '/000001-test-run-no-pending.sql',
			'CREATE TABLE test_run_no_pending (id integer);',
		);
		$conn = $this->connection(migrations: $dir);

		try {
			$_SERVER['argv'] = ['run', 'migrations', '--apply'];

			ob_start();
			$applyResult = new Runner(\Celemas\Quma\Commands::get($conn))->run();
			ob_end_clean();

			$_SERVER['argv'] = ['run', 'migrations', '--test-run'];

			ob_start();
			$testRunResult = new Runner(\Celemas\Quma\Commands::get($conn))->run();
			$content = ob_get_contents();
			ob_end_clean();

			$this->assertSame(0, $applyResult);
			$this->assertSame(0, $testRunResult);
			$this->assertStringContainsString('No migrations applied', $content);
			$this->assertStringNotContainsString('--test-run executes migrations', $content);
			$this->assertStringNotContainsString('Use --yes', $content);
		} finally {
			$this->removeMigrationDir($dir);
		}
	}

	public function testRunMigrationsTestRunRollsBackCreatedMetadataTable(): void
	{
		$dir = $this->createMigrationDir('test-run-metadata-rollback');
		file_put_contents(
			$dir . '/000001-test-run-metadata-rollback.sql',
			'CREATE TABLE test_run_metadata_rollback (id integer);',
		);
		$conn = $this->connection(migrations: $dir);

		try {
			$_SERVER['argv'] = ['run', 'migrations', '--test-run', '--yes'];

			ob_start();
			$result = new Runner(\Celemas\Quma\Commands::get($conn))->run();
			$content = ob_get_contents();
			ob_end_clean();

			$db = new Database($conn);
			$metadataTable = $db->execute(
				"SELECT count(*) AS available FROM sqlite_master WHERE type='table' AND name='migrations';",
			)->one(fetchMode: PDO::FETCH_ASSOC);
			$migrationTable = $db->execute(
				"SELECT count(*) AS available FROM sqlite_master WHERE type='table' AND name='test_run_metadata_rollback';",
			)->one(fetchMode: PDO::FETCH_ASSOC);

			$this->assertSame(0, $result);
			$this->assertStringContainsString('Rolled back 1 migration', $content);
			$this->assertSame(0, (int) ($metadataTable['available'] ?? 0));
			$this->assertSame(0, (int) ($migrationTable['available'] ?? 0));
		} finally {
			$this->removeMigrationDir($dir);
		}
	}

	public function testRunMigrationsRollsBackCreatedMetadataTableAfterError(): void
	{
		$dir = $this->createMigrationDir('metadata-rollback-error');
		file_put_contents($dir . '/000001-metadata-rollback-error.sql', 'RUBBISH;');
		$conn = $this->connection(migrations: $dir);

		try {
			$_SERVER['argv'] = ['run', 'migrations', '--apply'];

			ob_start();
			$result = new Runner(\Celemas\Quma\Commands::get($conn))->run();
			$content = ob_get_contents();
			ob_end_clean();

			$db = new Database($conn);
			$metadataTable = $db->execute(
				"SELECT count(*) AS available FROM sqlite_master WHERE type='table' AND name='migrations';",
			)->one(fetchMode: PDO::FETCH_ASSOC);

			$this->assertSame(1, $result);
			$this->assertStringContainsString('Due to errors no migrations applied', $content);
			$this->assertSame(0, (int) ($metadataTable['available'] ?? 0));
		} finally {
			$this->removeMigrationDir($dir);
		}
	}

	public function testRunMigrationsPlanDoesNotExecuteRenderOrRequireMigrations(): void
	{
		$dir = $this->createMigrationDir('plan-no-side-effects');
		$sqlTable = 'plan_should_not_run';
		$tpqlEffect = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quma-tpql-effect-' . uniqid();
		$phpEffect = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quma-php-effect-' . uniqid();
		file_put_contents($dir . '/000001-plan.sql', "CREATE TABLE {$sqlTable} (id integer);");
		file_put_contents(
			$dir . '/000002-plan.tpql',
			'<?php file_put_contents(' . var_export($tpqlEffect, true) . ", 'rendered'); ?>\nSELECT 1;",
		);
		file_put_contents(
			$dir . '/000003-plan.php',
			'<?php file_put_contents('
			. var_export($phpEffect, true)
			. ", 'required'); return stdClass::class;",
		);

		$conn = $this->connection(migrations: $dir);
		$db = new Database($conn);

		try {
			$_SERVER['argv'] = ['run', 'migrations'];

			ob_start();
			$result = new Runner(\Celemas\Quma\Commands::get($conn))->run();
			$content = ob_get_contents();
			ob_end_clean();

			$metadataTable = $db->execute(
				"SELECT count(*) AS available FROM sqlite_master WHERE type='table' AND name='migrations';",
			)->one(fetchMode: PDO::FETCH_ASSOC);
			$sqlMutationTable = $db->execute(
				"SELECT count(*) AS available FROM sqlite_master WHERE type='table' AND name='{$sqlTable}';",
			)->one(fetchMode: PDO::FETCH_ASSOC);

			$this->assertSame(0, $result);
			$this->assertStringContainsString('Plan only', $content);
			$this->assertStringContainsString('Would apply 3 migrations', $content);
			$this->assertSame(0, (int) ($metadataTable['available'] ?? 0));
			$this->assertSame(0, (int) ($sqlMutationTable['available'] ?? 0));
			$this->assertFileDoesNotExist($tpqlEffect);
			$this->assertFileDoesNotExist($phpEffect);
		} finally {
			if (is_file($tpqlEffect)) {
				unlink($tpqlEffect);
			}

			if (is_file($phpEffect)) {
				unlink($phpEffect);
			}

			$this->removeMigrationDir($dir);
		}
	}

	#[DataProvider('connectionProvider')]
	public function testRunMigrationsSuccess(string $dsn): void
	{
		$_SERVER['argv'] = ['run', 'migrations', '--apply'];
		$driver = strtok($dsn, ':');

		ob_start();
		$result = new Runner($this->commands(dsn: $dsn))->run();
		$content = ob_get_contents();
		ob_end_clean();

		$this->assertSame(0, $result);
		$this->assertMatchesRegularExpression('/000000-000000-migration.sql[^\n]*?success/', $content);
		$this->assertMatchesRegularExpression('/000000-000001-migration.php[^\n]*?success/', $content);
		$this->assertMatchesRegularExpression('/000000-000002-migration.tpql[^\n]*?success/', $content);
		$this->assertMatchesRegularExpression(
			'/000000-000005-migration-\[' . $driver . '\].sql[^\n]*?success/',
			$content,
		);
		$this->assertStringContainsString('4 migrations successfully applied', $content);
	}

	#[DataProvider('connectionProvider')]
	#[Depends('testRunMigrationsSuccess')]
	public function testRunMigrationsAgain(string $dsn): void
	{
		$_SERVER['argv'] = ['run', 'migrations', '--apply'];

		ob_start();
		new Runner($this->commands(dsn: $dsn))->run();
		ob_end_clean();

		ob_start();
		$result = new Runner($this->commands(dsn: $dsn))->run();
		$content = ob_get_contents();
		ob_end_clean();

		$this->assertSame(0, $result);
		$this->assertDoesNotMatchRegularExpression(
			'/000000-000000-migration.sql[^\n]*?success/',
			$content,
		);
		$this->assertStringContainsString('No migrations applied', $content);
	}

	public function testAddMigrationSql(): void
	{
		// Run existing migrations first
		ob_start();
		$_SERVER['argv'] = ['run', 'migrations', '--apply'];
		new Runner($this->commands())->run();
		ob_end_clean();

		// add the migrations
		ob_start();
		$_SERVER['argv'] = ['run', 'add-migration', '--file', 'test migration'];
		$migration = new Runner($this->commands())->run();
		ob_end_clean();

		$this->assertIsString($migration);
		$this->assertFileExists($migration);
		$this->assertStringStartsWith(TestCase::root(), $migration);
		$this->assertStringEndsWith('.sql', $migration);

		// Add content and run it
		file_put_contents($migration, 'SELECT 1;');

		// Re-run migrations
		ob_start();
		$_SERVER['argv'] = ['run', 'migrations', '--apply'];
		$result = new Runner($this->commands())->run();
		$content = ob_get_contents();
		ob_end_clean();
		if (is_file($migration)) {
			unlink($migration);
		}

		$this->assertFileDoesNotExist($migration);
		$this->assertSame(0, $result);
		$this->assertMatchesRegularExpression('/' . basename($migration) . '[^\n]*?success/', $content);
		$this->assertStringContainsString('1 migration successfully applied', $content);
	}

	public function testAddMigrationTpql(): void
	{
		$_SERVER['argv'] = ['run', 'add-migration', '--file', 'test migration.tpql'];

		ob_start();
		$migration = new Runner($this->commands())->run();
		ob_end_clean();

		$this->assertIsString($migration);
		$this->assertFileExists($migration);
		$this->assertStringStartsWith(TestCase::root(), $migration);
		$this->assertStringEndsWith('.tpql', $migration);
		$this->assertStringNotContainsString('.sql', $migration);

		$content = file_get_contents($migration);

		if (is_file($migration)) {
			unlink($migration);
		}
		$this->assertFileDoesNotExist($migration);
		$this->assertStringContainsString('<?php if', $content);
	}

	public function testAddMigrationPhp(): void
	{
		$_SERVER['argv'] = ['run', 'add-migration', '--file', 'test migration.php'];

		ob_start();
		$migration = new Runner($this->commands())->run();
		ob_end_clean();

		$this->assertIsString($migration);
		$this->assertFileExists($migration);
		$this->assertStringStartsWith(TestCase::root(), $migration);
		$this->assertStringEndsWith('.php', $migration);
		$this->assertStringNotContainsString('.sql', $migration);

		$content = file_get_contents($migration);
		$migrationClass = require $migration;

		if (is_file($migration)) {
			unlink($migration);
		}
		$this->assertFileDoesNotExist($migration);

		$this->assertStringContainsString('namespace Quma\\Migrations\\M', $content);
		$this->assertStringContainsString('class Migration implements Contract\\Migration', $content);
		$this->assertStringContainsString('run(Environment $env): void', $content);
		$this->assertIsString($migrationClass);
		$this->assertTrue(is_subclass_of($migrationClass, MigrationContract::class));
	}

	public function testAddMigrationWithWrongFileExtension(): void
	{
		$_SERVER['argv'] = ['run', 'add-migration', '-f', 'test.exe'];

		ob_start();
		new Runner($this->commands())->run();
		$output = ob_get_contents();
		ob_end_clean();

		$this->assertStringContainsString('Wrong file extension', $output);
	}

	public function testWrongMigrationsDirectory(): void
	{
		$this->expectException(ValueError::class);
		$this->expectExceptionMessage('Path does not exist: not/available');

		$this->connection(migrations: 'not/available');
	}

	public function testAddMigrationToVendor(): void
	{
		$_SERVER['argv'] = ['run', 'add-migration', '-f', 'test'];

		ob_start();
		new Runner($this->commands(migrations: TestCase::root() . '/../vendor'))->run();
		$output = ob_get_contents();
		ob_end_clean();

		$this->assertStringContainsString("is inside './vendor'", $output);
	}

	#[DataProvider('failingSqlMigrationProvider')]
	public function testFailingSqlMigration(string $dsn, string $ext): void
	{
		$_SERVER['argv'] = ['run', 'add-migration', '--file', "test-migration-failing{$ext}"];

		ob_start();
		$migration = new Runner($this->commands(dsn: $dsn))->run();

		// Add content and run it
		file_put_contents($migration, 'RUBBISH;');
		$_SERVER['argv'] = ['run', 'migrations', '--apply', '--stacktrace'];

		$result = new Runner($this->commands(dsn: $dsn))->run();
		$content = ob_get_contents();
		ob_end_clean();
		if (is_file($migration)) {
			unlink($migration);
		}

		$this->assertFileDoesNotExist($migration);
		$this->assertSame(1, $result);
		$this->assertStringContainsString("\n#0", $content);

		if (str_starts_with($dsn, 'mysql')) {
			$this->assertStringContainsString('applied until the error occured', $content);
			$this->assertStringContainsString('SQLSTATE[42000]', $content);
		} elseif (str_starts_with($dsn, 'pgsql')) {
			$this->assertStringContainsString('Due to errors no migrations applied', $content);
			$this->assertStringContainsString('SQLSTATE[42601]', $content);
		} else {
			$this->assertStringContainsString('Due to errors no migrations applied', $content);
			$this->assertStringContainsString('SQLSTATE[HY000]', $content);
		}
	}

	#[DataProvider('failingPhpMigrationProvider')]
	public function testFailingTpqlPhpMigrationPhpError(string $dsn, string $ext): void
	{
		$_SERVER['argv'] = ['run', 'add-migration', '--file', "test-migration-php-failing.{$ext}"];

		ob_start();
		$migration = new Runner($this->commands(dsn: $dsn))->run();

		// Add content and run it
		file_put_contents($migration, '<?php echo if)');
		$_SERVER['argv'] = ['run', 'migrations', '--apply'];

		$result = new Runner($this->commands(dsn: $dsn))->run();
		$content = ob_get_contents();
		ob_end_clean();
		if (is_file($migration)) {
			unlink($migration);
		}

		$this->assertFileDoesNotExist($migration);
		$this->assertSame(1, $result);

		if (str_starts_with($dsn, 'mysql')) {
			$this->assertStringContainsString('applied until the error occured', $content);
		} else {
			$this->assertStringContainsString('Due to errors no migrations applied', $content);
		}
	}

	public function testFailingDueToReadonlyMigrationsDirectory(): void
	{
		$tmpdir = sys_get_temp_dir() . '/chuck' . (string) mt_rand();
		mkdir($tmpdir, 0o400);

		$_SERVER['argv'] = ['run', 'add-migration', '--file', 'test-migration.sql'];

		ob_start();
		new Runner($this->commands(migrations: $tmpdir))->run();
		$content = ob_get_contents();
		ob_end_clean();

		rmdir($tmpdir);

		$this->assertStringContainsString('directory is not writable', $content);
	}

	public function testRunMigrationsWithNamespace(): void
	{
		// Create namespaced migration structure - must use arrays not single strings
		$conn = $this->connection(
			migrations: [
				'default' => [TestCase::root() . 'migrations'],
				'feature' => [TestCase::root() . 'sql/additional'],
			],
		);

		$_SERVER['argv'] = ['run', 'migrations', '--apply'];

		ob_start();
		$result = new Runner($this->commands())->run();
		ob_end_clean();

		// Run migration with specific namespace
		$_SERVER['argv'] = ['run', 'migrations', '--namespace', 'feature', '--apply'];

		ob_start();
		$result = new Runner(\Celemas\Quma\Commands::get($conn))->run();
		$content = ob_get_contents();
		ob_end_clean();

		$this->assertSame(0, $result);
		$this->assertStringContainsString('No migrations applied', $content);
	}

	public function testRunMigrationsWithNonExistentNamespace(): void
	{
		// Create namespaced migration structure
		$conn = $this->connection(
			migrations: [
				'default' => [TestCase::root() . 'migrations'],
				'feature' => [TestCase::root() . 'sql/additional'],
			],
		);

		$_SERVER['argv'] = ['run', 'migrations', '--namespace', 'nonexistent', '--apply'];

		ob_start();
		$result = new Runner(\Celemas\Quma\Commands::get($conn))->run();
		$content = ob_get_contents();
		ob_end_clean();

		$this->assertSame(1, $result);
		$this->assertStringContainsString("Migration namespace 'nonexistent' does not exist", $content);
	}

	public function testRunMigrationsWithoutDefaultNamespace(): void
	{
		// Create namespaced migration structure without 'default'
		$conn = $this->connection(
			migrations: [
				'feature' => [TestCase::root() . 'migrations'],
			],
		);

		$_SERVER['argv'] = ['run', 'migrations', '--apply'];

		ob_start();
		$result = new Runner(\Celemas\Quma\Commands::get($conn))->run();
		$content = ob_get_contents();
		ob_end_clean();

		$this->assertSame(1, $result);
		$this->assertStringContainsString("Migration namespace 'default' does not exist", $content);
		$this->assertStringContainsString('--namespace', $content);
	}

	public function testRunMigrationsUsesCustomMetadataNames(): void
	{
		$dir = $this->createMigrationDir('custom-metadata');
		file_put_contents(
			$dir . '/000001-custom.sql',
			'CREATE TABLE custom_metadata_result (id integer);',
		);

		$conn = $this
			->connection(migrations: $dir)
			->migrationTable('quma_migrations_custom')
			->migrationColumns('version', 'executed_at');

		try {
			$_SERVER['argv'] = ['run', 'migrations', '--apply'];

			ob_start();
			$result = new Runner(\Celemas\Quma\Commands::get($conn))->run();
			ob_end_clean();

			$db = new Database($conn);
			$rows = $db->execute(
				'SELECT version FROM quma_migrations_custom',
			)->all(fetchMode: PDO::FETCH_ASSOC);
			$defaultTable = $db->execute(
				"SELECT count(*) AS available FROM sqlite_master WHERE type='table' AND name='migrations';",
			)->one(fetchMode: PDO::FETCH_ASSOC);

			$this->assertSame(0, $result);
			$this->assertSame([['version' => '000001-custom.sql']], $rows);
			$this->assertSame(0, (int) ($defaultTable['available'] ?? 0));
		} finally {
			$this->removeMigrationDir($dir);
		}
	}

	public function testRunMigrationsUsesPlaceholders(): void
	{
		$dir = $this->createMigrationDir('static-placeholders');
		file_put_contents(
			$dir . '/000001-static-sql.sql',
			'CREATE TABLE [::table.sql::] (id integer);',
		);
		file_put_contents(
			$dir . '/000002-static-tpql.tpql',
			<<<'TPQL'
				<?php if ($driver === 'sqlite') : ?>
				CREATE TABLE [::table-tpql::] (id integer);
				<?php endif ?>
				TPQL,
		);

		$conn = new Connection(
			$this->getDsn(),
			TestCase::root() . 'sql/default',
		)
			->migrations($dir)
			->placeholders(Delimiters::brackets(), [
				'all' => [
					'table.sql' => 'static_sql_migration',
					'table-tpql' => 'static_tpql_migration',
				],
			]);

		try {
			$_SERVER['argv'] = ['run', 'migrations', '--apply'];

			ob_start();
			$result = new Runner(\Celemas\Quma\Commands::get($conn))->run();
			ob_end_clean();

			$db = new Database($conn);
			$sqlTable = $db->execute(
				"SELECT count(*) AS available FROM sqlite_master WHERE type='table' AND name='static_sql_migration';",
			)->one(fetchMode: PDO::FETCH_ASSOC);
			$tpqlTable = $db->execute(
				"SELECT count(*) AS available FROM sqlite_master WHERE type='table' AND name='static_tpql_migration';",
			)->one(fetchMode: PDO::FETCH_ASSOC);
			$this->assertSame(0, $result);
			$this->assertSame(1, (int) ($sqlTable['available'] ?? 0));
			$this->assertSame(1, (int) ($tpqlTable['available'] ?? 0));
		} finally {
			$this->removeMigrationDir($dir);
		}
	}

	public function testRunMigrationsRejectsDuplicateMigrationIdsBeforeSideEffects(): void
	{
		$firstDir = $this->createMigrationDir('duplicate-first');
		$secondDir = $this->createMigrationDir('duplicate-second');
		$firstMigration = $firstDir . '/000001-duplicate.sql';
		$secondMigration = $secondDir . '/000001-duplicate.sql';
		file_put_contents($firstMigration, 'CREATE TABLE duplicate_migration_first (id integer);');
		file_put_contents($secondMigration, 'CREATE TABLE duplicate_migration_second (id integer);');

		$conn = $this->connection(migrations: [$firstDir, $secondDir]);

		try {
			$_SERVER['argv'] = ['run', 'migrations', '--apply'];

			ob_start();
			$result = new Runner(\Celemas\Quma\Commands::get($conn))->run();
			$content = ob_get_contents();
			ob_end_clean();

			$db = new Database($conn);
			$metadataTable = $db->execute(
				"SELECT count(*) AS available FROM sqlite_master WHERE type='table' AND name='migrations';",
			)->one(fetchMode: PDO::FETCH_ASSOC);
			$firstTable = $db->execute(
				"SELECT count(*) AS available FROM sqlite_master WHERE type='table' AND name='duplicate_migration_first';",
			)->one(fetchMode: PDO::FETCH_ASSOC);
			$secondTable = $db->execute(
				"SELECT count(*) AS available FROM sqlite_master WHERE type='table' AND name='duplicate_migration_second';",
			)->one(fetchMode: PDO::FETCH_ASSOC);

			$this->assertSame(1, $result);
			$this->assertStringContainsString(
				"Duplicate migration id '000001-duplicate.sql' in namespace 'default'",
				$content,
			);
			$this->assertStringContainsString($firstMigration, $content);
			$this->assertStringContainsString($secondMigration, $content);
			$this->assertStringNotContainsString("Created table 'migrations'", $content);
			$this->assertSame(0, (int) ($metadataTable['available'] ?? 0));
			$this->assertSame(0, (int) ($firstTable['available'] ?? 0));
			$this->assertSame(0, (int) ($secondTable['available'] ?? 0));
		} finally {
			$this->removeMigrationDir($firstDir);
			$this->removeMigrationDir($secondDir);
		}
	}

	public function testRunMigrationsUsesCustomPlaceholderDelimiters(): void
	{
		$dir = $this->createMigrationDir('custom-delimiters');
		file_put_contents(
			$dir . '/000001-custom-delimiters.sql',
			'CREATE TABLE [[table.sql]] (id integer);',
		);
		file_put_contents(
			$dir . '/000002-custom-delimiters.tpql',
			<<<'TPQL'
				<?php if ($driver === 'sqlite') : ?>
				CREATE TABLE [[table-tpql]] (id integer);
				<?php endif ?>
				TPQL,
		);

		$conn = new Connection(
			$this->getDsn(),
			TestCase::root() . 'sql/default',
		)
			->migrations($dir)
			->placeholders(new Delimiters('[[', ']]'), [
				'all' => [
					'table.sql' => 'custom_delimiter_sql_migration',
					'table-tpql' => 'custom_delimiter_tpql_migration',
				],
			]);

		try {
			$_SERVER['argv'] = ['run', 'migrations', '--apply'];

			ob_start();
			$result = new Runner(\Celemas\Quma\Commands::get($conn))->run();
			ob_end_clean();

			$db = new Database($conn);
			$sqlTable = $db->execute(
				"SELECT count(*) AS available FROM sqlite_master WHERE type='table' AND name='custom_delimiter_sql_migration';",
			)->one(fetchMode: PDO::FETCH_ASSOC);
			$tpqlTable = $db->execute(
				"SELECT count(*) AS available FROM sqlite_master WHERE type='table' AND name='custom_delimiter_tpql_migration';",
			)->one(fetchMode: PDO::FETCH_ASSOC);
			$this->assertSame(0, $result);
			$this->assertSame(1, (int) ($sqlTable['available'] ?? 0));
			$this->assertSame(1, (int) ($tpqlTable['available'] ?? 0));
		} finally {
			$this->removeMigrationDir($dir);
		}
	}

	public function testRunMigrationsSkipsSqlThatBecomesEmptyAfterPlaceholderSubstitution(): void
	{
		$dir = $this->createMigrationDir('empty-placeholder');
		file_put_contents($dir . '/000001-empty-placeholder.sql', '[::empty::]');

		$conn = new Connection(
			$this->getDsn(),
			TestCase::root() . 'sql/default',
		)
			->migrations($dir)
			->placeholders(Delimiters::brackets(), ['all' => ['empty' => '']]);

		try {
			$_SERVER['argv'] = ['run', 'migrations', '--apply'];

			ob_start();
			$result = new Runner(\Celemas\Quma\Commands::get($conn))->run();
			$content = ob_get_contents();
			ob_end_clean();

			$this->assertSame(0, $result);
			$this->assertStringContainsString('is empty. Skipped', $content);
			$this->assertStringContainsString('No migrations applied', $content);
		} finally {
			$this->removeMigrationDir($dir);
		}
	}

	public function testTpqlMigrationSupportsGeneratedPlaceholders(): void
	{
		$dir = $this->createMigrationDir('generated-static-placeholder');
		file_put_contents(
			$dir . '/000001-generated.tpql',
			"CREATE TABLE <?= '[::table::]' ?> (id integer);",
		);

		$conn = new Connection(
			$this->getDsn(),
			TestCase::root() . 'sql/default',
		)
			->migrations($dir)
			->placeholders(Delimiters::brackets(), ['all' => ['table' => 'generated_static_migration']]);

		try {
			$_SERVER['argv'] = ['run', 'migrations', '--apply'];

			ob_start();
			$result = new Runner(\Celemas\Quma\Commands::get($conn))->run();
			ob_end_clean();

			$db = new Database($conn);
			$table = $db->execute(
				"SELECT count(*) AS available FROM sqlite_master WHERE type='table' AND name='generated_static_migration';",
			)->one(fetchMode: PDO::FETCH_ASSOC);

			$this->assertSame(0, $result);
			$this->assertSame(1, (int) ($table['available'] ?? 0));
		} finally {
			$this->removeMigrationDir($dir);
		}
	}

	public function testTpqlMigrationIgnoresUnknownPlaceholderInInactiveBranch(): void
	{
		$dir = $this->createMigrationDir('inactive-static-placeholder');
		file_put_contents(
			$dir . '/000001-inactive.tpql',
			<<<'TPQL'
				<?php if ($driver === 'pgsql') : ?>
				CREATE TABLE [::missing::] (id integer);
				<?php else : ?>
				CREATE TABLE inactive_static_placeholder_migration (id integer);
				<?php endif ?>
				TPQL,
		);

		$conn = $this->connection(migrations: $dir)
			->placeholders(Delimiters::brackets(), ['all' => ['unused' => 'unused']]);

		try {
			$_SERVER['argv'] = ['run', 'migrations', '--apply'];

			ob_start();
			$result = new Runner(\Celemas\Quma\Commands::get($conn))->run();
			ob_end_clean();

			$db = new Database($conn);
			$table = $db->execute(
				"SELECT count(*) AS available FROM sqlite_master WHERE type='table' AND name='inactive_static_placeholder_migration';",
			)->one(fetchMode: PDO::FETCH_ASSOC);

			$this->assertSame(0, $result);
			$this->assertSame(1, (int) ($table['available'] ?? 0));
		} finally {
			$this->removeMigrationDir($dir);
		}
	}

	public function testMysqlPlanDoesNotMutateDatabase(): void
	{
		$dsn = $this->mysqlDsn();

		if ($dsn === null) {
			$this->markTestSkipped('MySQL is not available.');
		}

		$dir = $this->createMigrationDir('mysql-plan');
		file_put_contents(
			$dir . '/000001-mysql-plan.sql',
			'CREATE TABLE mysql_plan_mutation (id integer);',
		);

		$conn = $this->connection(dsn: $dsn, migrations: $dir);
		$db = new Database($conn);
		$db->execute('DROP TABLE IF EXISTS mysql_plan_mutation')->run();
		$db->execute('DROP TABLE IF EXISTS migrations')->run();

		try {
			$_SERVER['argv'] = ['run', 'migrations'];

			ob_start();
			$result = new Runner(\Celemas\Quma\Commands::get($conn))->run();
			$output = ob_get_contents();
			ob_end_clean();

			$metadataTable = $db->execute(
				"SELECT count(*) AS available FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'migrations';",
			)->one(fetchMode: PDO::FETCH_ASSOC);
			$mutationTable = $db->execute(
				"SELECT count(*) AS available FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'mysql_plan_mutation';",
			)->one(fetchMode: PDO::FETCH_ASSOC);

			$this->assertSame(0, $result);
			$this->assertStringContainsString('Plan only', $output);
			$this->assertStringContainsString('Would apply 1 migration', $output);
			$this->assertStringContainsString('MySQL migrations are plan-only without --apply', $output);
			$this->assertSame(0, (int) ($metadataTable['available'] ?? 0));
			$this->assertSame(0, (int) ($mutationTable['available'] ?? 0));
		} finally {
			$db->execute('DROP TABLE IF EXISTS mysql_plan_mutation')->run();
			$db->execute('DROP TABLE IF EXISTS migrations')->run();
			$this->removeMigrationDir($dir);
		}
	}

	public function testRunMigrationsStoresNamespaceInMigrationId(): void
	{
		$defaultDir = $this->createMigrationDir('default-namespace');
		$featureDir = $this->createMigrationDir('feature-namespace');
		file_put_contents($defaultDir . '/000001-shared.sql', 'CREATE TABLE ns_default (id integer);');
		file_put_contents($featureDir . '/000001-shared.sql', 'CREATE TABLE ns_feature (id integer);');

		$conn = $this->connection(
			migrations: [
				'default' => [$defaultDir],
				'feature' => [$featureDir],
			],
		);

		try {
			$_SERVER['argv'] = ['run', 'migrations', '--apply'];

			ob_start();
			$defaultResult = new Runner(\Celemas\Quma\Commands::get($conn))->run();
			ob_end_clean();

			$_SERVER['argv'] = ['run', 'migrations', '--namespace', 'feature', '--apply'];

			ob_start();
			$featureResult = new Runner(\Celemas\Quma\Commands::get($conn))->run();
			ob_end_clean();

			$db = new Database($conn);
			$rows = $db->execute(
				'SELECT migration FROM migrations ORDER BY migration',
			)->all(fetchMode: PDO::FETCH_ASSOC);
			$defaultTable = $db->execute(
				"SELECT count(*) AS available FROM sqlite_master WHERE type='table' AND name='ns_default';",
			)->one(fetchMode: PDO::FETCH_ASSOC);
			$featureTable = $db->execute(
				"SELECT count(*) AS available FROM sqlite_master WHERE type='table' AND name='ns_feature';",
			)->one(fetchMode: PDO::FETCH_ASSOC);

			$this->assertSame(0, $defaultResult);
			$this->assertSame(0, $featureResult);
			$this->assertSame(
				['000001-shared.sql', 'feature:000001-shared.sql'],
				array_column($rows, 'migration'),
			);
			$this->assertSame(1, (int) ($defaultTable['available'] ?? 0));
			$this->assertSame(1, (int) ($featureTable['available'] ?? 0));
		} finally {
			$this->removeMigrationDir($defaultDir);
			$this->removeMigrationDir($featureDir);
		}
	}

	private function mysqlDsn(): ?string
	{
		foreach (TestCase::getAvailableDsns() as $dsn) {
			if (str_starts_with($dsn, 'mysql')) {
				return $dsn;
			}
		}

		return null;
	}

	private function createMigrationDir(string $suffix): string
	{
		$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quma-migrations-' . $suffix . '-' . uniqid();
		mkdir($dir, 0o700, true);

		return $dir;
	}

	private function removeMigrationDir(string $dir): void
	{
		$files = glob($dir . '/*');

		if (is_array($files)) {
			foreach ($files as $file) {
				if (!is_file($file)) {
					continue;
				}

				unlink($file);
			}
		}

		if (is_dir($dir)) {
			rmdir($dir);
		}
	}

	public static function connectionProvider(): array
	{
		return array_map(static fn($dsn) => [$dsn], TestCase::getAvailableDsns());
	}

	public static function transactionConnectionProvider(): array
	{
		return array_map(static fn($dsn) => [$dsn], TestCase::getAvailableDsns(transactionsOnly: true));
	}

	public static function migrationExtensionProvider(): array
	{
		return [['.sql'], ['.tpql']];
	}

	public static function phpMigrationExtensionProvider(): array
	{
		return [['php'], ['tpql']];
	}

	public static function failingSqlMigrationProvider(): array
	{
		$connections = TestCase::getAvailableDsns();
		$extensions = ['.sql', '.tpql'];
		$result = [];

		foreach ($connections as $dsn) {
			foreach ($extensions as $ext) {
				$result[] = [$dsn, $ext];
			}
		}

		return $result;
	}

	public static function failingPhpMigrationProvider(): array
	{
		$connections = TestCase::getAvailableDsns();
		$extensions = ['php', 'tpql'];
		$result = [];

		foreach ($connections as $dsn) {
			foreach ($extensions as $ext) {
				$result[] = [$dsn, $ext];
			}
		}

		return $result;
	}
}
