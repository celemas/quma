<?php

declare(strict_types=1);

namespace Celema\Quma\Tests;

use Celema\Console\BufferedIo;
use Celema\Quma\Connection;
use Celema\Quma\Environment;
use Celema\Quma\Migrations\DriverPolicy;
use Celema\Quma\Migrations\Executor;
use Celema\Quma\Migrations\Log;
use Celema\Quma\Migrations\PhpLoader;
use Celema\Quma\Migrations\Planner;
use Celema\Quma\Migrations\Runner;
use Celema\Quma\Migrations\RunOptions;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
final class MigrationRunnerTest extends TestCase
{
	private string $dir;
	private BufferedIo $io;
	private Environment $env;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quma-runner-' . uniqid();
		mkdir($this->dir, 0o700);
		$_SERVER['argv'] = ['run'];
		$this->io = new BufferedIo();
		$this->env = new Environment(
			['default' => new Connection('sqlite:' . $this->dir . '/db.sqlite3', self::root() . 'sql/default')],
			[],
		);
		$this->env->db->execute('CREATE TABLE migrations (migration text, applied text)')->run();
	}

	protected function tearDown(): void
	{
		$this->env->db->disconnect();

		foreach ((array) glob($this->dir . DIRECTORY_SEPARATOR . '*') as $file) {
			unlink((string) $file);
		}

		rmdir($this->dir);
		parent::tearDown();
	}

	/** @param array{string, bool, int} $state */
	#[DataProvider('transactionalFinishProvider')]
	public function testFinishInsideATransaction(
		array $state,
		int $exit,
		string $output,
		string $errors,
	): void {
		[$result, $apply, $numApplied] = $state;
		$db = $this->env->db;
		$db->begin();

		$this->assertSame($exit, $this->runner('sqlite')->finish($db, $result, $apply, $numApplied));
		$this->assertFalse($db->getConn()->inTransaction());
		$this->assertSame($output, $this->io->output());
		$this->assertSame($errors, $this->io->errorOutput());
	}

	/** @return array<string, array{array{string, bool, int}, int, string, string}> */
	public static function transactionalFinishProvider(): array
	{
		return [
			'error' => [[Executor::ERROR, true, 1], 1, '', "\nDue to errors no migrations applied\n"],
			'nothing applied' => [[Executor::SUCCESS, true, 0], 0, "\nNo migrations applied\n", ''],
			'applied' => [[Executor::SUCCESS, true, 1], 0, "\n1 migration successfully applied\n", ''],
			'test run' => [
				[Executor::SUCCESS, false, 2],
				0,
				"\nNotice: Test run only\nRolled back 2 migrations. Use --apply to commit them\n",
				'',
			],
		];
	}

	/** @param array{string, bool, int} $state */
	#[DataProvider('nonTransactionalFinishProvider')]
	public function testFinishWithoutTransactions(
		array $state,
		int $exit,
		string $output,
		string $errors,
	): void {
		[$result, $apply, $numApplied] = $state;

		$this->assertSame($exit, $this->runner('mysql')->finish($this->env->db, $result, $apply, $numApplied));
		$this->assertSame($output, $this->io->output());
		$this->assertSame($errors, $this->io->errorOutput());
	}

	/** @return array<string, array{array{string, bool, int}, int, string, string}> */
	public static function nonTransactionalFinishProvider(): array
	{
		return [
			'error' => [[Executor::ERROR, true, 1], 1, '', "\n1 migration applied until the error occured\n"],
			'applied' => [[Executor::SUCCESS, true, 2], 0, "\n2 migrations successfully applied\n", ''],
			'nothing applied' => [[Executor::SUCCESS, true, 0], 0, "\nNo migrations applied\n", ''],
		];
	}

	public function testRunStopsAtTheFirstFailingMigration(): void
	{
		$failing = $this->migration('000001-failing.sql', 'SELECT * FROM missing_table;');
		$next = $this->migration('000002-next.sql', 'CREATE TABLE next_table (id INTEGER);');

		$exit = $this->runner('sqlite')->run(
			'default',
			[$failing, $next],
			new RunOptions(false, true, true, static fn(): int => 0),
		);

		$this->assertSame(1, $exit);
		$this->assertStringNotContainsString('000002-next.sql', $this->io->output());
		$this->assertSame(
			[],
			$this->env->db->execute("SELECT name FROM sqlite_master WHERE name = 'next_table'")->all(),
		);
	}

	private function runner(string $driver): Runner
	{
		$policy = new DriverPolicy($driver);
		$planner = new Planner($policy);
		$log = new Log($this->env, $planner);

		return new Runner(
			$this->env,
			$policy,
			$planner,
			$log,
			new Executor($this->env, $log, new PhpLoader($this->env), $this->io),
			$this->io,
		);
	}

	private function migration(string $file, string $content): string
	{
		$path = $this->dir . DIRECTORY_SEPARATOR . $file;
		file_put_contents($path, $content);

		return $path;
	}
}
