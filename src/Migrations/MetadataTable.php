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
			$io->error("Table '%s' already exists. Aborting", $env->table);

			return 1;
		}

		$ddl = $env->getMigrationsTableDDL();

		if ($ddl !== false) {
			try {
				$db->execute($ddl)->run();
				$io->line("<bright-green>Success</bright-green>: Created table '%s'", $env->table);

				return 0;

				// Would require to create additional errornous DDL or to
				// setup a different test database. Too much effort.
				// @codeCoverageIgnoreStart
			} catch (Throwable $e) {
				$io->error("Error: While trying to create table '%s'", $env->table);
				$io->error('%s', $e->getMessage());

				if ($env->showStacktrace) {
					$io->error('%s', $e->getTraceAsString());
				}

				return 1;

				// @codeCoverageIgnoreEnd
			}
		}

		// Cannot be reliably tested.
		// Would require an unsupported driver to be installed.
		// @codeCoverageIgnoreStart
		$io->error("PDO driver '%s' not supported. Aborting", $env->driver);

		return 1;

		// @codeCoverageIgnoreEnd
	}
}
