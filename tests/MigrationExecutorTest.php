<?php

declare(strict_types=1);

namespace Celema\Quma\Tests;

use Celema\Console\BufferedIo;
use Celema\Console\Io;
use Celema\Quma\Connection;
use Celema\Quma\Database;
use Celema\Quma\Delimiters;
use Celema\Quma\Environment;
use Celema\Quma\Migrations\DriverPolicy;
use Celema\Quma\Migrations\Executor;
use Celema\Quma\Migrations\Log;
use Celema\Quma\Migrations\PhpLoader;
use Celema\Quma\Migrations\Planner;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
class MigrationExecutorTest extends TestCase
{
	private string $dir;
	private string $file;

	public function testReportsUnreadableMigration(): void
	{
		$missing = sys_get_temp_dir() . '/missing-migration-' . uniqid() . '.tpql';
		if (is_file($missing)) {
			unlink($missing);
		}

		$handler = set_error_handler(static fn(): bool => true);
		try {
			ob_start();
			$result = $this->executor()->migrate('default', $missing, false);
			$output = ob_get_contents();
			ob_end_clean();
		} finally {
			if ($handler !== null) {
				restore_error_handler();
			}
		}

		$this->assertSame(Executor::ERROR, $result);
		$this->assertSame(
			"Error: while working on migration '" . basename($missing) . "'\nCould not read migration file\n",
			(string) $output,
		);
	}

	#[DataProvider('emptyMigrationProvider')]
	public function testSkipsMigrationsWithoutStatements(string $file, string $content): void
	{
		$path = $this->migration($file, $content);
		$io = new BufferedIo();

		$result = $this->executorWithIo($io, ['all' => ['blank' => " \n"]])->migrate('default', $path, false);

		$this->assertSame(Executor::WARNING, $result);
		$this->assertSame("Warning: Migration '{$file}' is empty. Skipped\n", $io->errorOutput());
		$this->assertSame('', $io->output());
		$this->assertSame([], $this->applied());
	}

	/** @return array<string, array{string, string}> */
	public static function emptyMigrationProvider(): array
	{
		return [
			'whitespace sql' => ['blank.sql', " \n\t\n"],
			'whitespace php' => ['blank.php', " \n"],
			'sql compiling to whitespace' => ['placeholder.sql', '[::blank::]'],
			'template rendering whitespace' => ['template.tpql', "<?php if (false) : ?>SELECT 1;<?php endif ?>\n "],
		];
	}

	public function testAppliesPhpMigration(): void
	{
		$class = 'QumaExecutorTest\\M' . uniqid() . '\\Migration';
		$path = $this->phpMigration(
			'create.php',
			$class,
			'$env->db->execute("CREATE TABLE created (id INTEGER)")->run();',
		);
		$io = new BufferedIo();

		$result = $this->executorWithIo($io)->migrate('default', $path, false);

		$this->assertSame(Executor::SUCCESS, $result);
		$this->assertSame("Success: Migration 'create.php' successfully applied\n", $io->output());
		$this->assertSame('', $io->errorOutput());
		$this->assertSame(['create.php'], $this->applied());
		$this->assertSame([], $this->db()->execute('SELECT * FROM created')->all());
	}

	public function testReportsFailingPhpMigration(): void
	{
		$class = 'QumaExecutorTest\\M' . uniqid() . '\\Migration';
		$path = $this->phpMigration('fail.php', $class, 'throw new \\RuntimeException("boom");');
		$io = new BufferedIo();

		$result = $this->executorWithIo($io)->migrate('default', $path, false);

		$this->assertSame(Executor::ERROR, $result);
		$this->assertSame("Error: while working on migration 'fail.php'\nboom\n", $io->errorOutput());
		$this->assertSame('', $io->output());
		$this->assertSame([], $this->applied());
	}

	public function testFailingTemplateClosesItsOutputBuffer(): void
	{
		$path = $this->migration('fail.tpql', 'SELECT 1;<?php throw new RuntimeException("boom"); ?>');
		$io = new BufferedIo();
		$level = ob_get_level();

		$result = $this->executorWithIo($io)->migrate('default', $path, false);

		$this->assertSame(Executor::ERROR, $result);
		$this->assertSame($level, ob_get_level());
		$this->assertSame("Error: while working on migration 'fail.tpql'\nboom\n", $io->errorOutput());
	}

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quma-executor-' . uniqid();
		mkdir($this->dir, 0o700);
		$this->file = $this->dir . DIRECTORY_SEPARATOR . 'db.sqlite3';
		$this->db()->execute('CREATE TABLE migrations (migration text, applied text)')->run();
	}

	protected function tearDown(): void
	{
		foreach ((array) glob($this->dir . DIRECTORY_SEPARATOR . '*') as $file) {
			unlink((string) $file);
		}

		rmdir($this->dir);
		parent::tearDown();
	}

	/** @param array<array-key, mixed> $placeholders */
	private function executorWithIo(Io $io, array $placeholders = []): Executor
	{
		$_SERVER['argv'] = ['run'];
		$conn = new Connection('sqlite:' . $this->file, self::root() . 'sql/default');

		if ($placeholders !== []) {
			$conn->placeholders(Delimiters::brackets(), $placeholders);
		}

		$env = new Environment(['default' => $conn], []);

		return new Executor($env, $this->log($env), new PhpLoader($env), $io);
	}

	private function db(): Database
	{
		return new Database(new Connection('sqlite:' . $this->file, self::root() . 'sql/default'));
	}

	/** @return list<string> */
	private function applied(): array
	{
		return array_column($this->db()->execute('SELECT migration FROM migrations')->all(), 'migration');
	}

	private function migration(string $file, string $content): string
	{
		$path = $this->dir . DIRECTORY_SEPARATOR . $file;
		file_put_contents($path, $content);

		return $path;
	}

	private function phpMigration(string $file, string $class, string $body): string
	{
		$namespace = substr($class, 0, (int) strrpos($class, '\\'));

		return $this->migration($file, <<<PHP
			<?php

			namespace {$namespace};

			use Celema\\Quma\\Contract;
			use Celema\\Quma\\Environment;

			class Migration implements Contract\\Migration
			{
				public function run(Environment \$env): void
				{
					{$body}
				}
			}

			return Migration::class;
			PHP);
	}

	public function testLogRecordsAndReadsMigrations(): void
	{
		$_SERVER['argv'] = ['run'];
		$conn = $this->connection();
		$db = new Database($conn);
		$db->execute('DROP TABLE IF EXISTS migrations')->run();
		$db->execute('CREATE TABLE migrations (migration text, applied text)')->run();
		$log = $this->log(new Environment(['default' => $conn], []));

		try {
			$log->record($db, 'cms', '/migrations/000001-users.sql');

			$this->assertSame(['cms:000001-users.sql'], $log->applied($db));
		} finally {
			$db->execute('DROP TABLE IF EXISTS migrations')->run();
		}
	}

	private function executor(): Executor
	{
		$_SERVER['argv'] = ['run'];
		$env = new Environment(['default' => $this->connection()], []);

		return new Executor(
			$env,
			$this->log($env),
			new PhpLoader($env),
			new Io('php://output', 'php://output'),
		);
	}

	private function log(Environment $env): Log
	{
		return new Log($env, new Planner(new DriverPolicy($env->driver)));
	}
}
