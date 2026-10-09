<?php

declare(strict_types=1);

namespace Celema\Quma\Commands;

use Celema\Console\Arg;
use Celema\Console\Command;
use Celema\Console\Io;
use Celema\Console\Opt;
use Celema\Quma\Connection;
use Celema\Quma\Environment;

#[Command('db:add-migration', 'Initialize a new migration', group: 'Database')]
final class Add
{
	/** @param array<non-empty-string, Connection>|Connection $connections */
	public function __construct(
		private readonly array|Connection $connections,
		private readonly array $options = [],
	) {}

	public function __invoke(
		Io $io,
		#[Arg('Name of the migration script; prompted for interactively when omitted')]
		string $name = '',
		#[Opt('Connection to use', value: 'name')]
		string $conn = 'default',
	): int {
		$env = new Environment($this->connections, $this->options, $conn);
		$fileName = $this->fileName($name, $io);

		if ($fileName === null) {
			return 1;
		}

		// fileName() returns a lowercase name.
		$ext = pathinfo($fileName, PATHINFO_EXTENSION);
		$migrations = $env->conn->config->migrations;

		if (count($migrations) === 0) {
			$io->line('No migration directories configured. Aborting.');

			return 1;
		}

		// Get the first migrations directory from the config
		// Handles both flat list and namespaced formats
		$migrationsDir = $this->getFirstMigrationDir($migrations);

		if ($migrationsDir === null) {
			$io->line('No valid migration directory found. Aborting.');

			return 1;
		}

		if (str_contains($migrationsDir, '/vendor')) {
			$io->line("The migrations directory is inside './vendor'.\n  -> %s\nAborting.", $migrationsDir);

			return 1;
		}

		if (!is_writable($migrationsDir)) {
			$io->line("Migrations directory is not writable\n  -> %s\nAborting. ", $migrationsDir);

			return 1;
		}

		$timestamp = date('ymd-His', time());

		$migration = $migrationsDir . DIRECTORY_SEPARATOR . $timestamp . '-' . $fileName;
		$f = fopen($migration, 'w');

		if ($f === false) {
			$io->line("Could not create migration file: %s\nAborting.", $migration);

			return 1;
		}

		if ($ext === 'php') {
			fwrite($f, $this->getPhpContent($fileName, $timestamp));
		} elseif ($ext === 'tpql') {
			fwrite($f, $this->getTpqlContent());
		}

		fclose($f);
		$io->line("Migration created:\n%s", $migration);

		return 0;
	}

	/**
	 * Resolves the migration file name from the argument or a prompt.
	 *
	 * Returns null when no name was provided or the extension is invalid.
	 */
	private function fileName(string $fileName, Io $io): ?string
	{
		if ($fileName === '') {
			$fileName = $io->ask('Name of the migration script:');

			if ($fileName === '') {
				$io->line('No input provided. Aborting.');

				return null;
			}
		}

		$fileName = strtolower(str_replace([' ', '_'], '-', $fileName));
		$ext = pathinfo($fileName, PATHINFO_EXTENSION);

		if (!$ext) {
			return $fileName . '.sql';
		}

		if (!in_array($ext, ['sql', 'php', 'tpql'], strict: true)) {
			$io->line("Wrong file extension '%s'. Use 'sql', 'php' or 'tpql' instead.\nAborting.", $ext);

			return null;
		}

		return $fileName;
	}

	private function getPhpContent(string $fileName, string $timestamp): string
	{
		$name = $this->getPhpMigrationName($fileName);
		$namespace = 'Quma\\Migrations\\M' . str_replace('-', '_', $timestamp) . '_' . $name;

		return "<?php

declare(strict_types=1);

namespace {$namespace};

use Celema\\Quma\\Contract;
use Celema\\Quma\\Environment;

class Migration implements Contract\\Migration
{
    public function run(Environment \$env): void
    {
        throw new \\LogicException('Implement migration {$name} before running it.');
    }
}

return Migration::class;";
	}

	private function getPhpMigrationName(string $fileName): string
	{
		$parts = preg_split(
			'/[^a-zA-Z0-9]+/',
			pathinfo($fileName, PATHINFO_FILENAME),
			-1,
			PREG_SPLIT_NO_EMPTY,
		);

		if ($parts === false || count($parts) === 0) {
			return 'Migration';
		}

		// The file name is already lowercase.
		return implode('', array_map(ucfirst(...), $parts));
	}

	private function getTpqlContent(): string
	{
		return "<?php if (\$driver === 'pgsql') : ?>

<?php else : ?>

<?php endif ?>
";
	}

	/**
	 * Gets the first migration directory from the config.
	 *
	 * Handles both flat list and namespaced formats.
	 *
	 * @param array<int|string, string|list<string>> $migrations
	 */
	private function getFirstMigrationDir(array $migrations): ?string
	{
		$first = reset($migrations);

		if ($first === false) {
			return null; // @codeCoverageIgnore
		}

		// If it's a string, return it directly
		if (is_string($first)) {
			return $first;
		}

		// It's a list, return the first element
		return $first[0] ?? null;
	}
}
