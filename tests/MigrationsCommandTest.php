<?php

declare(strict_types=1);

namespace Celemas\Quma\Tests;

use Celemas\Quma\Commands\Migrations;
use Celemas\Quma\Connection;
use Celemas\Quma\Contract\Migration as MigrationContract;
use Celemas\Quma\Contract\MigrationFactory;
use Celemas\Quma\Database;
use Celemas\Quma\Environment;
use PDO;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * @internal
 */
class MigrationsCommandTest extends TestCase
{
	public function testRunMigrationsHandlesUnreadableFile(): void
	{
		$_SERVER['argv'] = ['run'];
		$conn = $this->connection();
		$db = new Database($conn);
		$db->execute('DROP TABLE IF EXISTS migrations')->run();
		$db->execute('CREATE TABLE migrations (migration text, applied text)')->run();

		$missing = sys_get_temp_dir() . '/missing-migration.sql';
		if (is_file($missing)) {
			unlink($missing);
		}

		$command = new Migrations($conn);
		$method = new ReflectionMethod(Migrations::class, 'runMigrations');

		$handler = set_error_handler(static fn(): bool => true);
		try {
			ob_start();
			$result = $method->invoke($command, 'default', [$missing], false, true, true);
			$output = ob_get_contents();
			ob_end_clean();
		} finally {
			if ($handler !== null) {
				restore_error_handler();
			}
		}

		$this->assertSame(1, $result);
		$this->assertStringContainsString('Could not read migration file', $output);
	}

	public function testMigrateTpqlHandlesMissingFile(): void
	{
		$_SERVER['argv'] = ['run'];
		$conn = $this->connection();
		$db = new Database($conn);
		$command = new Migrations($conn);
		$method = new ReflectionMethod(Migrations::class, 'migrateTPQL');

		$missing = sys_get_temp_dir() . '/missing-migration-' . uniqid() . '.tpql';
		if (is_file($missing)) {
			unlink($missing);
		}

		ob_start();
		$result = $method->invoke($command, $db, $conn, 'default', $missing, false);
		$output = ob_get_contents();
		ob_end_clean();

		$this->assertSame('error', $result);
		$this->assertStringContainsString('Could not read migration file', $output);
	}

	public function testLoadPhpMigrationThrowsWhenFileMissing(): void
	{
		$_SERVER['argv'] = ['run'];
		$conn = $this->connection();
		$command = new Migrations($conn);
		$method = new ReflectionMethod(Migrations::class, 'loadPhpMigration');

		$missing = sys_get_temp_dir() . '/missing-migration-' . uniqid() . '.php';

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not read migration file');

		$method->invoke($command, $missing);
	}

	public function testLoadPhpMigrationThrowsWhenFileReturnsWrongValue(): void
	{
		$_SERVER['argv'] = ['run'];
		$conn = $this->connection();
		$command = new Migrations($conn);
		$method = new ReflectionMethod(Migrations::class, 'loadPhpMigration');

		$migration = sys_get_temp_dir() . '/invalid-migration-' . uniqid() . '.php';
		file_put_contents($migration, '<?php return new stdClass();');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Expected migration class name');

		try {
			$method->invoke($command, $migration);
		} finally {
			if (is_file($migration)) {
				unlink($migration);
			}
		}
	}

	public function testLoadPhpMigrationThrowsWhenClassDoesNotExist(): void
	{
		$_SERVER['argv'] = ['run'];
		$conn = $this->connection();
		$command = new Migrations($conn);
		$method = new ReflectionMethod(Migrations::class, 'loadPhpMigration');

		$class = 'MissingMigration' . str_replace('.', '_', uniqid('', true));
		$migration = sys_get_temp_dir() . '/missing-class-migration-' . uniqid() . '.php';
		file_put_contents($migration, "<?php return '{$class}';");

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Migration class '{$class}' does not exist");

		try {
			$method->invoke($command, $migration);
		} finally {
			if (is_file($migration)) {
				unlink($migration);
			}
		}
	}

	public function testLoadPhpMigrationThrowsWhenClassDoesNotImplementContract(): void
	{
		$_SERVER['argv'] = ['run'];
		$conn = $this->connection();
		$command = new Migrations($conn);
		$method = new ReflectionMethod(Migrations::class, 'loadPhpMigration');

		$namespace = 'Quma\\Tests\\InvalidMigration_' . str_replace('.', '_', uniqid('', true));
		$migration = sys_get_temp_dir() . '/wrong-contract-migration-' . uniqid() . '.php';
		file_put_contents(
			$migration,
			"<?php namespace {$namespace}; final class NotAMigration {} return NotAMigration::class;",
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('must implement ' . MigrationContract::class);

		try {
			$method->invoke($command, $migration);
		} finally {
			if (is_file($migration)) {
				unlink($migration);
			}
		}
	}

	public function testLoadPhpMigrationRequiresFactoryForConstructorArguments(): void
	{
		$_SERVER['argv'] = ['run'];
		$conn = $this->connection();
		$command = new Migrations($conn);
		$method = new ReflectionMethod(Migrations::class, 'loadPhpMigration');

		$migration = $this->writeConstructorMigration();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage(
			'requires constructor arguments, but no migration factory is configured',
		);

		try {
			$method->invoke($command, $migration);
		} finally {
			if (is_file($migration)) {
				unlink($migration);
			}
		}
	}

	public function testLoadPhpMigrationCachesClassNameAfterRequiringFile(): void
	{
		$_SERVER['argv'] = ['run'];
		$conn = $this->connection();
		$firstCommand = new Migrations($conn);
		$secondCommand = new Migrations($conn);
		$method = new ReflectionMethod(Migrations::class, 'loadPhpMigration');

		$migration = $this->writeSimpleMigration();

		try {
			$firstMigrationObject = $method->invoke($firstCommand, $migration);
			$secondMigrationObject = $method->invoke($secondCommand, $migration);
		} finally {
			if (is_file($migration)) {
				unlink($migration);
			}
		}

		$this->assertInstanceOf(MigrationContract::class, $firstMigrationObject);
		$this->assertInstanceOf(MigrationContract::class, $secondMigrationObject);
	}

	public function testLoadPhpMigrationUsesFactory(): void
	{
		$_SERVER['argv'] = ['run'];
		$conn = $this->connection();
		$factory = new class implements MigrationFactory {
			public bool $called = false;

			/** @param class-string<MigrationContract> $class */
			public function create(string $class, Environment $env): MigrationContract
			{
				$this->called = true;

				return new $class('injected');
			}
		};
		$command = new Migrations($conn, migrationFactory: $factory);
		$method = new ReflectionMethod(Migrations::class, 'loadPhpMigration');

		$migration = $this->writeConstructorMigration();

		try {
			$migrationObject = $method->invoke($command, $migration);
		} finally {
			if (is_file($migration)) {
				unlink($migration);
			}
		}

		$this->assertInstanceOf(MigrationContract::class, $migrationObject);
		$this->assertTrue($factory->called);
	}

	public function testMysqlDryRunPlansPendingMigrationsWithoutRunningThem(): void
	{
		if (!in_array('mysql', PDO::getAvailableDrivers(), strict: true)) {
			$this->markTestSkipped('PDO MySQL is not available.');
		}

		$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quma-mysql-plan-' . uniqid();
		mkdir($dir, 0o700, true);
		file_put_contents(
			$dir . '/000001-plan.sql',
			'CREATE TABLE mysql_plan_should_not_run (id integer);',
		);

		$_SERVER['argv'] = ['run'];
		$conn = new Connection(
			'mysql:host=localhost;dbname=quma;user=quma;password=quma',
			$this->getSqlDirs(),
		)->migrations($dir);
		$command = new Migrations($conn);
		$method = new ReflectionMethod(Migrations::class, 'planMigrations');

		try {
			ob_start();
			$result = $method->invoke($command, '', false);
			$output = ob_get_contents();
			ob_end_clean();
		} finally {
			$this->removeMigrationDir($dir);
		}

		$this->assertSame(0, $result);
		$this->assertStringContainsString("Would create migrations table 'migrations'", $output);
		$this->assertStringContainsString('Would apply 1 migration', $output);
		$this->assertStringContainsString('000001-plan.sql', $output);
		$this->assertStringContainsString('MySQL migrations are not executed during dry runs', $output);
	}

	public function testRunPlansMysqlDryRunWithoutConnecting(): void
	{
		$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quma-mysql-run-plan-' . uniqid();
		mkdir($dir, 0o700, true);
		file_put_contents(
			$dir . '/000001-run-plan.sql',
			'CREATE TABLE mysql_run_plan_should_not_run (id integer);',
		);

		$_SERVER['argv'] = ['run', 'migrations'];
		$conn = $this->connection(migrations: $dir);
		$command = $this->commandWithEnv($this->fakeEnv($conn, 'mysql', false));

		try {
			ob_start();
			$result = $command->run();
			$output = ob_get_contents();
			ob_end_clean();
		} finally {
			$this->removeMigrationDir($dir);
		}

		$this->assertSame(0, $result);
		$this->assertStringContainsString("Would create migrations table 'migrations'", (string) $output);
		$this->assertStringContainsString('Would apply 1 migration', (string) $output);
		$this->assertStringContainsString(
			'MySQL migrations are not executed during dry runs',
			(string) $output,
		);
	}

	public function testRunCreatesMetadataBeforeMysqlApply(): void
	{
		$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quma-mysql-run-apply-' . uniqid();
		mkdir($dir, 0o700, true);
		file_put_contents(
			$dir . '/000001-run-apply.sql',
			'CREATE TABLE mysql_run_apply_precreate (id integer);',
		);

		$_SERVER['argv'] = ['run', 'migrations', '--apply'];
		$conn = $this->connection(migrations: $dir);
		$db = new Database($conn);
		$db->execute('DROP TABLE IF EXISTS migrations')->run();
		$db->execute('DROP TABLE IF EXISTS mysql_run_apply_precreate')->run();
		$command = $this->commandWithEnv($this->fakeEnv($conn, 'mysql', false));

		try {
			ob_start();
			$result = $command->run();
			ob_end_clean();

			$this->assertSame(0, $result);
			$this->assertSame(
				1,
				$db->execute(
					"SELECT count(*) AS available FROM sqlite_master WHERE type = 'table' AND name = 'migrations'",
				)->one(fetchMode: PDO::FETCH_ASSOC)['available'],
			);
			$this->assertSame(
				1,
				$db->execute(
					"SELECT count(*) AS available FROM sqlite_master WHERE type = 'table' AND name = 'mysql_run_apply_precreate'",
				)->one(fetchMode: PDO::FETCH_ASSOC)['available'],
			);
		} finally {
			$db->execute('DROP TABLE IF EXISTS mysql_run_apply_precreate')->run();
			$db->execute('DROP TABLE IF EXISTS migrations')->run();
			$this->removeMigrationDir($dir);
		}
	}

	public function testPendingMigrationsSkipsAppliedAndUnsupportedDriverFiles(): void
	{
		$_SERVER['argv'] = ['run'];
		$conn = $this->connection();
		$command = new Migrations($conn);
		$method = new ReflectionMethod(Migrations::class, 'pendingMigrations');

		$result = $method->invoke(
			$command,
			'default',
			[
				'000001-applied.sql',
				'000002-pgsql-[pgsql].sql',
				'000003-sqlite-[sqlite].sql',
			],
			['000001-applied.sql'],
		);

		$this->assertSame(['000003-sqlite-[sqlite].sql'], $result);
	}

	public function testFinishHandlesNonTransactionalDrivers(): void
	{
		$_SERVER['argv'] = ['run'];
		$mysqlConn = new Connection(
			'mysql:host=localhost;dbname=quma;user=quma;password=quma',
			$this->getSqlDirs(),
		)->migrations(TestCase::root() . 'migrations');
		$command = new Migrations($mysqlConn);
		$db = new Database($mysqlConn);
		$method = new ReflectionMethod(Migrations::class, 'finish');

		ob_start();
		$resultError = $method->invoke($command, $db, 'error', true, 2);
		$outputError = ob_get_contents();
		ob_end_clean();

		$this->assertSame(1, $resultError);
		$this->assertStringContainsString('2 migrations applied until the error occured', $outputError);

		ob_start();
		$resultSuccess = $method->invoke($command, $db, 'success', true, 2);
		$outputSuccess = ob_get_contents();
		ob_end_clean();

		$this->assertSame(0, $resultSuccess);
		$this->assertStringContainsString('2 migrations successfully applied', $outputSuccess);

		ob_start();
		$resultEmpty = $method->invoke($command, $db, 'success', true, 0);
		$outputEmpty = ob_get_contents();
		ob_end_clean();

		$this->assertSame(0, $resultEmpty);
		$this->assertStringContainsString('No migrations applied', $outputEmpty);
	}

	public function testSupportsTransactionsForPgsql(): void
	{
		$_SERVER['argv'] = ['run'];
		$pgsqlConn = new Connection(
			'pgsql:host=localhost;dbname=quma;user=quma;password=quma',
			$this->getSqlDirs(),
		)->migrations(TestCase::root() . 'migrations');
		$command = new Migrations($pgsqlConn);
		$method = new ReflectionMethod(Migrations::class, 'supportsTransactions');

		$this->assertTrue($method->invoke($command));
	}

	private function writeSimpleMigration(): string
	{
		$namespace = 'Quma\\Tests\\SimpleMigration_' . str_replace('.', '_', uniqid('', true));
		$migration = sys_get_temp_dir() . '/simple-migration-' . uniqid() . '.php';
		file_put_contents($migration, <<<PHP
			<?php

			declare(strict_types=1);

			namespace {$namespace};

			use Celemas\\Quma\\Contract;
			use Celemas\\Quma\\Environment;

			final class Migration implements Contract\\Migration
			{
			    public function run(Environment \$env): void {}
			}

			return Migration::class;
			PHP);

		return $migration;
	}

	private function writeConstructorMigration(): string
	{
		$namespace = 'Quma\\Tests\\ConstructorMigration_' . str_replace('.', '_', uniqid('', true));
		$migration = sys_get_temp_dir() . '/constructor-migration-' . uniqid() . '.php';
		file_put_contents($migration, <<<PHP
			<?php

			declare(strict_types=1);

			namespace {$namespace};

			use Celemas\\Quma\\Contract;
			use Celemas\\Quma\\Environment;

			final class Migration implements Contract\\Migration
			{
			    public function __construct(private string \$value) {}

			    public function run(Environment \$env): void
			    {
			        if (\$this->value === '') {
			            return;
			        }
			    }
			}

			return Migration::class;
			PHP);

		return $migration;
	}

	private function commandWithEnv(Environment $env): Migrations
	{
		$command = new ReflectionClass(Migrations::class)->newInstanceWithoutConstructor();
		assert($command instanceof Migrations, 'Reflection must create a Migrations command.');

		new ReflectionProperty(Migrations::class, 'env')->setValue($command, $env);

		return $command;
	}

	private function fakeEnv(Connection $conn, string $driver, bool $tableExists): Environment
	{
		$env = new class($tableExists) extends Environment {
			public function __construct(
				private readonly bool $tableExists,
			) {}

			public function checkIfMigrationsTableExists(Database $db): bool
			{
				return $this->tableExists;
			}
		};

		$this->setEnvProperty($env, 'conn', $conn);
		$this->setEnvProperty($env, 'driver', $driver);
		$this->setEnvProperty($env, 'showStacktrace', false);
		$this->setEnvProperty($env, 'table', $conn->config->migrationsTable);
		$this->setEnvProperty($env, 'columnMigration', $conn->config->migrationsColumnMigration);
		$this->setEnvProperty($env, 'columnApplied', $conn->config->migrationsColumnApplied);
		$this->setEnvProperty($env, 'db', new Database($conn));
		$this->setEnvProperty($env, 'options', []);

		return $env;
	}

	private function setEnvProperty(Environment $env, string $name, mixed $value): void
	{
		new ReflectionProperty(Environment::class, $name)->setValue($env, $value);
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
}
