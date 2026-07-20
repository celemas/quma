<?php

declare(strict_types=1);

namespace Celema\Quma\Migrations;

use Celema\Console\Io;
use Celema\Quma\Database;
use Celema\Quma\Environment;
use Throwable;

final readonly class MetadataTable
{
	public function __construct(
		private Environment $env,
		private Io $io,
	) {}

	public function create(Database $db): int
	{
		$env = $this->env;
		$io = $this->io;

		if ($env->checkIfMigrationsTableExists($db)) {
			$io->echolnErr("Table '{$env->table}' already exists. Aborting");

			return 1;
		}

		$ddl = $env->getMigrationsTableDDL();

		if ($ddl !== false) {
			try {
				$db->execute($ddl)->run();
				$io->echoln("<bright-green>Success</bright-green>: Created table '{$env->table}'");

				return 0;

				// Would require to create additional errornous DDL or to
				// setup a different test database. Too much effort.
				// @codeCoverageIgnoreStart
			} catch (Throwable $e) {
				$io->echolnErr("<bright-red>Error</bright-red>: While trying to create table '{$env->table}'");
				$io->echolnErr($io->escape($e->getMessage()));

				if ($env->showStacktrace) {
					$io->echolnErr($io->escape($e->getTraceAsString()));
				}

				return 1;

				// @codeCoverageIgnoreEnd
			}
		}

		// Cannot be reliably tested.
		// Would require an unsupported driver to be installed.
		// @codeCoverageIgnoreStart
		$io->echolnErr("PDO driver '{$env->driver}' not supported. Aborting");

		return 1;

		// @codeCoverageIgnoreEnd
	}
}
