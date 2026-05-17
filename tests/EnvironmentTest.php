<?php

declare(strict_types=1);

namespace Celemas\Quma\Tests;

use Celemas\Quma\Config;
use Celemas\Quma\Environment;
use Celemas\Quma\Tests\Util\FakeDatabase;
use ReflectionProperty;

/**
 * @internal
 */
class EnvironmentTest extends TestCase
{
	public function testGetMigrationsReturnsFalseWithoutDirs(): void
	{
		$_SERVER['argv'] = ['run'];
		$env = new Environment(['default' => $this->connection(migrations: [])], []);

		ob_start();
		$result = $env->getMigrations();
		$output = ob_get_contents();
		ob_end_clean();

		$this->assertFalse($result);
		$this->assertStringContainsString('No migration directories defined', $output);
	}

	public function testGetMigrationsHandlesFlatAndNamespacedDirs(): void
	{
		$flatDir = $this->createMigrationDir('flat');
		$namespacedDir = $this->createMigrationDir('namespaced');

		file_put_contents($flatDir . '/20240101-000000-a.sql', 'SELECT 1;');
		file_put_contents($flatDir . '/20240101-000001-b.php', '<?php');
		file_put_contents($flatDir . '/20240101-000002-c.tpql', '<?php');
		file_put_contents($namespacedDir . '/20240101-000003-d.sql', 'SELECT 1;');

		$_SERVER['argv'] = ['run'];
		$flatEnv = new Environment(['default' => $this->connection(migrations: [$flatDir])], []);
		$flatMigrations = $flatEnv->getMigrations();
		$this->assertIsArray($flatMigrations);
		$this->assertArrayHasKey('default', $flatMigrations);
		$this->assertSame(
			[
				'20240101-000000-a.sql',
				'20240101-000001-b.php',
				'20240101-000002-c.tpql',
			],
			array_map('basename', $flatMigrations['default']),
		);

		$namespacedEnv = new Environment([
			'default' => $this->connection(migrations: [
				'alpha' => $flatDir,
				'beta' => [$namespacedDir],
			]),
		], []);
		$namespacedMigrations = $namespacedEnv->getMigrations();
		$this->assertIsArray($namespacedMigrations);
		$this->assertArrayHasKey('alpha', $namespacedMigrations);
		$this->assertArrayHasKey('beta', $namespacedMigrations);
		$this->assertSame(
			[
				'20240101-000000-a.sql',
				'20240101-000001-b.php',
				'20240101-000002-c.tpql',
			],
			array_map('basename', $namespacedMigrations['alpha']),
		);
		$this->assertSame(
			[
				'20240101-000003-d.sql',
			],
			array_map('basename', $namespacedMigrations['beta']),
		);

		$this->removeMigrationDir($flatDir);
		$this->removeMigrationDir($namespacedDir);
	}

	public function testGetMigrationsSkipsNonStringNamespaceKeys(): void
	{
		$dir = $this->createMigrationDir('namespaced-skip');
		file_put_contents($dir . '/20240101-000000-a.sql', 'SELECT 1;');

		$connection = $this->connection(migrations: [$dir]);
		$property = new ReflectionProperty(Config::class, 'migrations');
		$property->setValue($connection->config, [
			0 => $dir,
			'valid' => $dir,
		]);

		$_SERVER['argv'] = ['run'];
		$env = new Environment(['default' => $connection], []);
		$migrations = $env->getMigrations();

		$this->assertIsArray($migrations);
		$this->assertArrayHasKey('valid', $migrations);
		$this->assertArrayNotHasKey(0, $migrations);

		$this->removeMigrationDir($dir);
	}

	public function testGetMigrationsSkipsInvalidNamespaceDirectories(): void
	{
		$dir = $this->createMigrationDir('namespaced-invalid-dirs');
		file_put_contents($dir . '/20240101-000000-a.sql', 'SELECT 1;');

		$connection = $this->connection(migrations: [$dir]);
		$property = new ReflectionProperty(Config::class, 'migrations');
		$property->setValue($connection->config, [
			'valid' => [$dir],
			'invalidType' => 123,
			'emptyDirs' => [''],
		]);

		$_SERVER['argv'] = ['run'];
		$env = new Environment(['default' => $connection], []);
		$migrations = $env->getMigrations();

		$this->assertIsArray($migrations);
		$this->assertArrayHasKey('valid', $migrations);
		$this->assertArrayNotHasKey('invalidType', $migrations);
		$this->assertArrayNotHasKey('emptyDirs', $migrations);

		$this->removeMigrationDir($dir);
	}

	public function testCheckIfMigrationsTableExistsForDrivers(): void
	{
		$_SERVER['argv'] = ['run'];
		$mysqlEnv = new Environment([
			'default' => $this->connection(dsn: 'mysql:host=localhost;dbname=quma;user=quma;password=quma'),
		], []);
		$mysqlDb = new FakeDatabase($mysqlEnv->conn, 'mysql', 1);
		$this->assertTrue($mysqlEnv->checkIfMigrationsTableExists($mysqlDb));

		$pgsqlEnv = new Environment([
			'default' => $this->connection(dsn: 'pgsql:host=localhost;dbname=quma;user=quma;password=quma'),
		], []);
		$pgsqlDb = new FakeDatabase($pgsqlEnv->conn, 'pgsql', 0);
		$this->assertFalse($pgsqlEnv->checkIfMigrationsTableExists($pgsqlDb));
	}

	public function testGetMigrationsTableDDLForDrivers(): void
	{
		$_SERVER['argv'] = ['run'];
		$mysqlEnv = new Environment([
			'default' => $this->connection(dsn: 'mysql:host=localhost;dbname=quma;user=quma;password=quma'),
		], []);
		$mysqlDDL = $mysqlEnv->getMigrationsTableDDL();
		$this->assertIsString($mysqlDDL);
		$this->assertStringContainsString('CREATE TABLE migrations', $mysqlDDL);

		$pgsqlEnv = new Environment([
			'default' => $this->connection(dsn: 'pgsql:host=localhost;dbname=quma;user=quma;password=quma'),
		], []);
		$pgsqlDDL = $pgsqlEnv->getMigrationsTableDDL();
		$this->assertIsString($pgsqlDDL);
		$this->assertStringContainsString('CREATE TABLE public.migrations', $pgsqlDDL);
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
}
