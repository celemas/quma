<?php

declare(strict_types=1);

namespace Celema\Quma\Commands;

use Celema\Console\Arg;
use Celema\Console\Args;
use Celema\Console\Command;
use Celema\Console\Io;
use Celema\Console\Opt;
use Celema\Quma\Connection;
use Celema\Quma\Environment;

#[Command('db:add-migration', 'Initialize a new migration', group: 'Database')]
#[Arg(
	'name',
	'Name of the migration script; prompted for interactively when omitted',
	optional: true,
)]
#[Opt('--conn', 'Connection to use', value: 'name')]
final class Add
{
	private readonly Environment $env;

	/** @param array<non-empty-string, Connection>|Connection $conn */
	public function __construct(array|Connection $conn, array $options = [])
	{
		$this->env = new Environment($conn, $options);
	}

	public function __invoke(Args $args, Io $io): int
	{
		$env = $this->env;
		$fileName = $this->fileName($args, $io);

		if ($fileName === null) {
			return 1;
		}

		$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
		$migrations = $env->conn->config->migrations;

		if (count($migrations) === 0) {
			$io->echoln('No migration directories configured. Aborting.');

			return 1;
		}

		// Get the first migrations directory from the config
		// Handles both flat list and namespaced formats
		$migrationsDir = $this->getFirstMigrationDir($migrations);

		if ($migrationsDir === null) {
			$io->echoln('No valid migration directory found. Aborting.');

			return 1;
		}

		if (str_contains($migrationsDir, '/vendor')) {
			$io->echoln(
				"The migrations directory is inside './vendor'.\n  -> {$migrationsDir}\nAborting.",
			);

			return 1;
		}

		if (!is_writable($migrationsDir)) {
			$io->echoln("Migrations directory is not writable\n  -> {$migrationsDir}\nAborting. ");

			return 1;
		}

		$timestamp = date('ymd-His', time());

		$migration = $migrationsDir . DIRECTORY_SEPARATOR . $timestamp . '-' . $fileName;
		$f = fopen($migration, 'w');

		if ($f === false) {
			$io->echoln("Could not create migration file: {$migration}\nAborting.");

			return 1;
		}

		if ($ext === 'php') {
			fwrite($f, $this->getPhpContent($fileName, $timestamp));
		} elseif ($ext === 'tpql') {
			fwrite($f, $this->getTpqlContent());
		}

		fclose($f);
		$io->echoln("Migration created:\n{$migration}");

		return 0;
	}

	/**
	 * Resolves the migration file name from the argument or a prompt.
	 *
	 * Returns null when no name was provided or the extension is invalid.
	 */
	private function fileName(Args $args, Io $io): ?string
	{
		$fileName = (string) $args->positional(0, '');

		if ($fileName === '') {
			$fileName = $io->ask('Name of the migration script:');

			if ($fileName === '') {
				$io->echoln('No input provided. Aborting.');

				return null;
			}
		}

		$fileName = strtolower(str_replace([' ', '_'], '-', $fileName));
		$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

		if (!$ext) {
			return $fileName . '.sql';
		}

		if (!in_array($ext, ['sql', 'php', 'tpql'], strict: true)) {
			$io->echoln("Wrong file extension '{$ext}'. Use 'sql', 'php' or 'tpql' instead.\nAborting.");

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

		$words = array_map(
			static fn(string $part): string => ucfirst(strtolower($part)),
			$parts,
		);

		return implode('', $words);
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
