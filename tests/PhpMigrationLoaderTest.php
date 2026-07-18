<?php

declare(strict_types=1);

namespace Celema\Quma\Tests;

use Celema\Quma\Contract\Migration as MigrationContract;
use Celema\Quma\Contract\MigrationFactory;
use Celema\Quma\Environment;
use Celema\Quma\Migrations\PhpLoader;
use RuntimeException;

/**
 * @internal
 */
class PhpMigrationLoaderTest extends TestCase
{
	public function testThrowsWhenFileMissing(): void
	{
		$missing = sys_get_temp_dir() . '/missing-migration-' . uniqid() . '.php';

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not read migration file');

		$this->loader()->load($missing);
	}

	public function testThrowsWhenFileReturnsWrongValue(): void
	{
		$migration = sys_get_temp_dir() . '/invalid-migration-' . uniqid() . '.php';
		file_put_contents($migration, '<?php return new stdClass();');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Expected migration class name');

		try {
			$this->loader()->load($migration);
		} finally {
			if (is_file($migration)) {
				unlink($migration);
			}
		}
	}

	public function testThrowsWhenClassDoesNotExist(): void
	{
		$class = 'MissingMigration' . str_replace('.', '_', uniqid('', true));
		$migration = sys_get_temp_dir() . '/missing-class-migration-' . uniqid() . '.php';
		file_put_contents($migration, "<?php return '{$class}';");

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Migration class '{$class}' does not exist");

		try {
			$this->loader()->load($migration);
		} finally {
			if (is_file($migration)) {
				unlink($migration);
			}
		}
	}

	public function testThrowsWhenClassDoesNotImplementContract(): void
	{
		$namespace = 'Quma\\Tests\\InvalidMigration_' . str_replace('.', '_', uniqid('', true));
		$migration = sys_get_temp_dir() . '/wrong-contract-migration-' . uniqid() . '.php';
		file_put_contents(
			$migration,
			"<?php namespace {$namespace}; final class NotAMigration {} return NotAMigration::class;",
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('must implement ' . MigrationContract::class);

		try {
			$this->loader()->load($migration);
		} finally {
			if (is_file($migration)) {
				unlink($migration);
			}
		}
	}

	public function testRequiresFactoryForConstructorArguments(): void
	{
		$migration = $this->writeConstructorMigration();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage(
			'requires constructor arguments, but no migration factory is configured',
		);

		try {
			$this->loader()->load($migration);
		} finally {
			if (is_file($migration)) {
				unlink($migration);
			}
		}
	}

	public function testCachesClassNameAfterRequiringFile(): void
	{
		$firstLoader = $this->loader();
		$secondLoader = $this->loader();
		$migration = $this->writeSimpleMigration();

		try {
			$firstMigrationObject = $firstLoader->load($migration);
			$secondMigrationObject = $secondLoader->load($migration);
		} finally {
			if (is_file($migration)) {
				unlink($migration);
			}
		}

		$this->assertInstanceOf(MigrationContract::class, $firstMigrationObject);
		$this->assertInstanceOf(MigrationContract::class, $secondMigrationObject);
	}

	public function testUsesFactory(): void
	{
		$factory = new class implements MigrationFactory {
			public bool $called = false;

			/** @param class-string<MigrationContract> $class */
			public function create(string $class, Environment $env): MigrationContract
			{
				$this->called = true;

				return new $class('injected');
			}
		};
		$loader = $this->loader($factory);
		$migration = $this->writeConstructorMigration();

		try {
			$migrationObject = $loader->load($migration);
		} finally {
			if (is_file($migration)) {
				unlink($migration);
			}
		}

		$this->assertInstanceOf(MigrationContract::class, $migrationObject);
		$this->assertTrue($factory->called);
	}

	private function loader(?MigrationFactory $migrationFactory = null): PhpLoader
	{
		$_SERVER['argv'] = ['run'];
		$env = new Environment(['default' => $this->connection()], []);

		return new PhpLoader($env, $migrationFactory);
	}

	private function writeSimpleMigration(): string
	{
		$namespace = 'Quma\\Tests\\SimpleMigration_' . str_replace('.', '_', uniqid('', true));
		$migration = sys_get_temp_dir() . '/simple-migration-' . uniqid() . '.php';
		file_put_contents($migration, <<<PHP
			<?php

			declare(strict_types=1);

			namespace {$namespace};

			use Celema\\Quma\\Contract;
			use Celema\\Quma\\Environment;

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

			use Celema\\Quma\\Contract;
			use Celema\\Quma\\Environment;

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
}
