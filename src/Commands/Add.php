<?php

declare(strict_types=1);

namespace Celemas\Quma\Commands;

use Celemas\Cli\Opts;
use Override;

final class Add extends Command
{
	protected string $name = 'add-migration';
	protected string $group = 'Database';
	protected string $prefix = 'db';
	protected string $description = 'Initialize a new migration';

	#[Override]
	public function run(): string|int
	{
		$env = $this->env;
		$opts = new Opts();
		$fileName = $opts->get('-f', $opts->get('--file', ''));

		if ($fileName === '') {
			// Would stop the test suit and wait for input
			// @codeCoverageIgnoreStart
			$input = readline('Name of the migration script: ');

			if ($input === false) {
				echo "No input provided. Aborting.\n";

				return 1;
			}
			$fileName = $input;

			// @codeCoverageIgnoreEnd
		}

		$fileName = str_replace(' ', '-', $fileName);
		$fileName = str_replace('_', '-', $fileName);
		$fileName = strtolower($fileName);
		$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

		if (!$ext) {
			$fileName .= '.sql';
		} else {
			if (!in_array($ext, ['sql', 'php', 'tpql'], strict: true)) {
				echo "Wrong file extension '{$ext}'. Use 'sql', 'php' or 'tpql' instead.\nAborting.\n";

				return 1;
			}
		}

		$migrations = $env->conn->config->migrations;

		if (count($migrations) === 0) {
			echo "No migration directories configured. Aborting.\n";

			return 1;
		}

		// Get the first migrations directory from the config
		// Handles both flat list and namespaced formats
		$migrationsDir = $this->getFirstMigrationDir($migrations);

		if ($migrationsDir === null) {
			echo "No valid migration directory found. Aborting.\n";

			return 1;
		}

		if (str_contains($migrationsDir, '/vendor')) {
			echo "The migrations directory is inside './vendor'.\n  -> {$migrationsDir}\nAborting.\n";

			return 1;
		}

		if (!is_writable($migrationsDir)) {
			echo "Migrations directory is not writable\n  -> {$migrationsDir}\nAborting. \n";

			return 1;
		}

		$timestamp = date('ymd-His', time());

		$migration = $migrationsDir . DIRECTORY_SEPARATOR . $timestamp . '-' . $fileName;
		$f = fopen($migration, 'w');

		if ($f === false) {
			echo "Could not create migration file: {$migration}\nAborting.\n";

			return 1;
		}

		if ($ext === 'php') {
			fwrite($f, $this->getPhpContent($fileName, $timestamp));
		} elseif ($ext === 'tpql') {
			fwrite($f, $this->getTpqlContent());
		}

		fclose($f);
		echo "Migration created:\n{$migration}\n";

		return $migration;
	}

	protected function getPhpContent(string $fileName, string $timestamp): string
	{
		// Translates what-is-up.sql into WhatIsUp
		$className =
			implode(
				'',
				explode(
					'-',
					explode(
						'.',
						ucwords($fileName, '-'),
					)[0],
				),
			)
			. '_'
			. str_replace('-', '_', $timestamp);

		return "<?php

declare(strict_types=1);

use Celemas\\Quma\\Environment;
use Celemas\\Quma\\MigrationInterface;

class {$className} implements MigrationInterface
{
    public function run(Environment \$env): void
    {
        throw new \\LogicException('Implement migration {$className} before running it.');
    }
}

return new {$className}();";
	}

	protected function getTpqlContent(): string
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
	protected function getFirstMigrationDir(array $migrations): ?string
	{
		$first = reset($migrations);

		if ($first === false) {
			return null;
		}

		// If it's a string, return it directly
		if (is_string($first)) {
			return $first;
		}

		// It's a list, return the first element
		return $first[0] ?? null;
	}
}
