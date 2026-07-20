<?php

declare(strict_types=1);

namespace Celema\Quma\Tests;

use Celema\Console\BufferedIo;
use Celema\Console\Runner;

/**
 * @internal
 */
class AddMigrationTest extends TestCase
{
	public function testAddMigrationPromptsWhenFileNameIsOmitted(): void
	{
		$_SERVER['argv'] = ['run', 'add-migration'];
		$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quma-migrations-prompt-' . uniqid();
		mkdir($dir, 0o700, recursive: true);
		$migration = null;

		try {
			$out = new BufferedIo("prompted migration\n");
			$exit = new Runner($this->commands(migrations: ['temp' => $dir]), $out)->run();
			$output = $out->output();

			preg_match('/Migration created:\s*(\S+)/', $output, $matches);
			$migration = $matches[1] ?? '';

			$this->assertSame(0, $exit);
			$this->assertStringContainsString('Name of the migration script:', $output);
			$this->assertStringEndsWith('prompted-migration.sql', $migration);
		} finally {
			if (is_string($migration) && is_file($migration)) {
				unlink($migration);
			}

			if (is_dir($dir)) {
				rmdir($dir);
			}
		}
	}

	public function testAddMigrationAbortsWithoutPromptInput(): void
	{
		$_SERVER['argv'] = ['run', 'add-migration'];
		$out = new BufferedIo();
		$exit = new Runner($this->commands(migrations: []), $out)->run();

		$this->assertSame(1, $exit);
		$this->assertStringContainsString('No input provided. Aborting.', $out->output());
	}

	public function testAddMigrationRejectsSurplusArguments(): void
	{
		$_SERVER['argv'] = ['run', 'add-migration', 'test.sql', 'extra'];
		$out = new BufferedIo();
		$exit = new Runner($this->commands(migrations: []), $out)->run();

		$this->assertSame(1, $exit);
		$this->assertStringContainsString("Unexpected argument 'extra'", $out->errorOutput());
	}

	public function testPhpMigrationNameFallsBackForPunctuationOnlyFileName(): void
	{
		// The `--` separator lets the dashed file name pass as a positional.
		$_SERVER['argv'] = ['run', 'add-migration', '--', '---.php'];
		$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quma-migrations-name-' . uniqid();
		mkdir($dir, 0o700, true);
		$migration = null;

		try {
			ob_start();
			$exit = $this->consoleRunner($this->commands(migrations: ['temp' => $dir]))->run();
			$output = (string) ob_get_clean();

			// run() now returns an exit code; the created path is printed as
			// "Migration created:\n<path>".
			preg_match('/Migration created:\s*(\S+)/', $output, $matches);
			$migration = $matches[1] ?? '';

			$this->assertSame(0, $exit);
			$this->assertNotSame('', $migration);
			$this->assertStringContainsString(
				'Implement migration Migration before running it.',
				(string) file_get_contents($migration),
			);
		} finally {
			if (is_string($migration) && is_file($migration)) {
				unlink($migration);
			}

			if (is_dir($dir)) {
				rmdir($dir);
			}
		}
	}

	public function testAddMigrationWithoutDirectories(): void
	{
		$_SERVER['argv'] = ['run', 'add-migration', 'test.sql'];

		ob_start();
		$result = $this->consoleRunner($this->commands(migrations: []))->run();
		$output = ob_get_contents();
		ob_end_clean();

		$this->assertSame(1, $result);
		$this->assertStringContainsString('No migration directories configured', $output);
	}

	public function testAddMigrationWithInvalidDirectory(): void
	{
		$_SERVER['argv'] = ['run', 'add-migration', 'test.sql'];

		ob_start();
		$result = $this->consoleRunner($this->commands(migrations: ['empty' => []]))->run();
		$output = ob_get_contents();
		ob_end_clean();

		$this->assertSame(1, $result);
		$this->assertStringContainsString('No valid migration directory found', $output);
	}

	public function testAddMigrationCannotCreateFile(): void
	{
		$_SERVER['argv'] = ['run', 'add-migration', 'test.sql'];
		$tempFile = tempnam(sys_get_temp_dir(), 'quma-migrations-');

		if ($tempFile === false) {
			$this->fail('Unable to create a temporary file for the test.');
		}

		$handler = set_error_handler(static fn(): bool => true);
		try {
			ob_start();
			$result = $this->consoleRunner($this->commands(migrations: ['temp' => $tempFile]))->run();
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
