<?php

declare(strict_types=1);

namespace Celemas\Quma\Tests;

use Celemas\Cli\Runner;
use Celemas\Quma\Commands\Add;
use ReflectionMethod;

/**
 * @internal
 */
class AddMigrationTest extends TestCase
{
	public function testGetFirstMigrationDirHandlesEmptyConfig(): void
	{
		$command = new Add($this->connection());
		$method = new ReflectionMethod(Add::class, 'getFirstMigrationDir');
		$this->assertNull($method->invoke($command, []));
	}

	public function testPhpMigrationNameFallsBackForPunctuationOnlyFileName(): void
	{
		$command = new Add($this->connection());
		$method = new ReflectionMethod(Add::class, 'getPhpMigrationName');

		$this->assertSame('Migration', $method->invoke($command, '---.php'));
	}

	public function testAddMigrationWithoutDirectories(): void
	{
		$_SERVER['argv'] = ['run', 'add-migration', '--file', 'test.sql'];

		ob_start();
		$result = new Runner($this->commands(migrations: []))->run();
		$output = ob_get_contents();
		ob_end_clean();

		$this->assertSame(1, $result);
		$this->assertStringContainsString('No migration directories configured', $output);
	}

	public function testAddMigrationWithInvalidDirectory(): void
	{
		$_SERVER['argv'] = ['run', 'add-migration', '--file', 'test.sql'];

		ob_start();
		$result = new Runner($this->commands(migrations: ['empty' => []]))->run();
		$output = ob_get_contents();
		ob_end_clean();

		$this->assertSame(1, $result);
		$this->assertStringContainsString('No valid migration directory found', $output);
	}

	public function testAddMigrationCannotCreateFile(): void
	{
		$_SERVER['argv'] = ['run', 'add-migration', '--file', 'test.sql'];
		$tempFile = tempnam(sys_get_temp_dir(), 'quma-migrations-');

		if ($tempFile === false) {
			$this->fail('Unable to create a temporary file for the test.');
		}

		$handler = set_error_handler(static fn(): bool => true);
		try {
			ob_start();
			$result = new Runner($this->commands(migrations: ['temp' => $tempFile]))->run();
			$output = ob_get_contents();
			ob_end_clean();
		} finally {
			if ($handler !== null) {
				restore_error_handler();
			}
		}

		if (is_file($tempFile)) {
			unlink($tempFile);
		}

		$this->assertSame(1, $result);
		$this->assertStringContainsString('Could not create migration file', $output);
	}
}
