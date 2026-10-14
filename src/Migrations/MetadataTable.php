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
		$driver = $db->getPdoDriver();
		$ddl = $env->getMigrationsTableDDL();

		if ($ddl === false || !new DriverPolicy($driver)->isKnown()) {
			$io->error("PDO driver '%s' not supported. Aborting", $driver);

			return 1;
		}

		if ($env->checkIfMigrationsTableExists($db)) {
			$io->error("Table '%s' already exists. Aborting", $env->table);

			return 1;
		}

		try {
			$db->execute($ddl)->run();
			$io->line("<bright-green>Success</bright-green>: Created table '%s'", $env->table);

			return 0;

			// Would require to create additional errornous DDL or to
			// setup a different test database. Too much effort.
			// @codeCoverageIgnoreStart
		} catch (Throwable $e) {
			$io->error("Error: While trying to create table '%s'", $env->table);
			$io->stderr()->line('%s', $e->getMessage());

			if ($env->showStacktrace) {
				$io->stderr()->line('%s', $e->getTraceAsString());
			}

			return 1;

			// @codeCoverageIgnoreEnd
		}
	}
}
