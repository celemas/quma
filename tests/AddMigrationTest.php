<?php

declare(strict_types=1);

namespace Celema\Quma\Tests;

use Celema\Console\Buffer;
use Celema\Console\Io;
use Celema\Console\Runner;

/**
 * @internal
 */
class AddMigrationTest extends TestCase
{
	/** @var list<string> */
	private array $tempDirs = [];

	public function testAddMigrationPromptsWhenFileNameIsOmitted(): void
	{
		$_SERVER['argv'] = ['run', 'add-migration'];
		$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quma-migrations-prompt-' . uniqid();
		mkdir($dir, 0o700, recursive: true);
		$migration = null;

		try {
			$buffer = new Buffer("prompted migration\n");
			$out = new Io($buffer);
			$exit = new Runner($this->commands(migrations: ['temp' => $dir]), $out)->run();
			$output = $buffer->output();

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
		$buffer = new Buffer();
		$out = new Io($buffer);
		$exit = new Runner($this->commands(migrations: []), $out)->run();

		$this->assertSame(1, $exit);
		$this->assertStringContainsString('No input provided. Aborting.', $buffer->output());
	}

	public function testAddMigrationRejectsSurplusArguments(): void
	{
		$_SERVER['argv'] = ['run', 'add-migration', 'test.sql', 'extra'];
		$buffer = new Buffer();
		$out = new Io($buffer);
		$exit = new Runner($this->commands(migrations: []), $out)->run();

		$this->assertSame(2, $exit);
		$this->assertStringContainsString("Unexpected argument 'extra'", $buffer->errorOutput());
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
		$this->assertSame("No migration directories configured. Aborting.\n", $output);
	}

	public function testAddMigrationWithInvalidDirectory(): void
	{
		$_SERVER['argv'] = ['run', 'add-migration', 'test.sql'];

		ob_start();
		$result = $this->consoleRunner($this->commands(migrations: ['empty' => []]))->run();
		$output = ob_get_contents();
		ob_end_clean();

		$this->assertSame(1, $result);
		$this->assertSame("No valid migration directory found. Aborting.\n", $output);
	}

	public function testAddMigrationCreatesTimestampedLowercaseSqlFile(): void
	{
		$dir = $this->tempDir();
		[$exit, $output] = $this->add(['Create Users_Table'], ['temp' => $dir]);

		$this->assertSame(0, $exit);
		$files = $this->files($dir);
		$this->assertCount(1, $files);
		$this->assertMatchesRegularExpression('/^\d{6}-\d{6}-create-users-table\.sql$/', $files[0]);
		$this->assertSame('', file_get_contents($dir . '/' . $files[0]));
		$this->assertSame("Migration created:\n" . realpath($dir) . "/{$files[0]}\n", $output);
	}

	public function testAddMigrationCreatesPhpMigrationClass(): void
	{
		$dir = $this->tempDir();
		[$exit] = $this->add(['add_user Roles.PHP'], ['temp' => $dir]);

		$this->assertSame(0, $exit);
		$files = $this->files($dir);
		$this->assertCount(1, $files);
		$this->assertMatchesRegularExpression('/^(\d{6})-(\d{6})-add-user-roles\.php$/', $files[0]);
		$timestamp = str_replace('-', '_', substr($files[0], 0, 13));
		$content = (string) file_get_contents($dir . '/' . $files[0]);
		$this->assertStringContainsString("namespace Quma\\Migrations\\M{$timestamp}_AddUserRoles;", $content);
		$this->assertStringContainsString('Implement migration AddUserRoles before running it.', $content);
	}

	public function testAddMigrationUsesFirstDirectoryOfFirstNamespace(): void
	{
		$first = $this->tempDir();
		$second = $this->tempDir();
		[$exit] = $this->add(['first'], ['temp' => [$first, $second]]);

		$this->assertSame(0, $exit);
		$this->assertCount(1, $this->files($first));
		$this->assertSame([], $this->files($second));
	}

	public function testAddMigrationWithoutPromptInputCreatesNoFile(): void
	{
		$dir = $this->tempDir();
		[$exit, $output, $errors] = $this->add([], ['temp' => $dir]);

		$this->assertSame(1, $exit);
		$this->assertStringEndsWith("No input provided. Aborting.\n", $output);
		$this->assertSame('', $errors);
		$this->assertSame([], $this->files($dir));
	}

	public function testAddMigrationRejectsWrongExtension(): void
	{
		$dir = $this->tempDir();
		[$exit, $output, $errors] = $this->add(['notes.txt'], ['temp' => $dir]);

		$this->assertSame(1, $exit);
		$this->assertSame("Wrong file extension 'txt'. Use 'sql', 'php' or 'tpql' instead.\nAborting.\n", $output);
		$this->assertSame('', $errors);
		$this->assertSame([], $this->files($dir));
	}

	public function testAddMigrationRejectsDirectoryInsideVendor(): void
	{
		$dir = $this->tempDir() . '/vendor';
		mkdir($dir);
		[$exit, $output] = $this->add(['vendored'], ['temp' => $dir]);
		$dir = (string) realpath($dir);

		$this->assertSame(1, $exit);
		$this->assertSame("The migrations directory is inside './vendor'.\n  -> {$dir}\nAborting.\n", $output);
		$this->assertSame([], $this->files($dir));
	}

	public function testAddMigrationRejectsReadOnlyDirectory(): void
	{
		$dir = $this->tempDir();
		chmod($dir, 0o500);

		try {
			[$exit, $output] = $this->add(['readonly'], ['temp' => $dir]);
		} finally {
			chmod($dir, 0o700);
		}

		$dir = (string) realpath($dir);
		$this->assertSame(1, $exit);
		$this->assertSame("Migrations directory is not writable\n  -> {$dir}\nAborting. \n", $output);
		$this->assertSame([], $this->files($dir));
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
		$this->assertMatchesRegularExpression(
			'/^Could not create migration file: .+\\nAborting\\.\\n$/',
			(string) $output,
		);
	}

	/**
	 * Runs the add-migration command and returns its exit code, output and error output.
	 *
	 * @param list<string> $args
	 * @param array<array-key, mixed> $migrations
	 * @return array{int, string, string}
	 */
	private function add(array $args, array $migrations, string $input = ''): array
	{
		$_SERVER['argv'] = ['run', 'add-migration', ...$args];
		$buffer = new Buffer($input);
		$io = new Io($buffer);
		$exit = new Runner($this->commands(migrations: $migrations), $io)->run();

		return [$exit, $buffer->output(), $buffer->errorOutput()];
	}

	/** @return list<string> */
	private function files(string $dir): array
	{
		$files = array_values(array_diff((array) scandir($dir), ['.', '..']));
		sort($files);

		return $files;
	}

	private function tempDir(): string
	{
		$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quma-add-' . uniqid();
		mkdir($dir, 0o700);
		$this->tempDirs[] = $dir;

		return $dir;
	}

	protected function tearDown(): void
	{
		foreach (array_reverse($this->tempDirs) as $dir) {
			$this->removeDir($dir);
		}

		$this->tempDirs = [];
		parent::tearDown();
	}

	private function removeDir(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}

		foreach ($this->files($dir) as $file) {
			$path = $dir . '/' . $file;
			is_dir($path) ? $this->removeDir($path) : unlink($path);
		}

		rmdir($dir);
	}
}
