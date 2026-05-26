<?php

declare(strict_types=1);

namespace Celemas\Quma\Migrations;

use Celemas\Quma\Database;
use Celemas\Quma\Environment;
use Throwable;

final readonly class MetadataTable
{
	public function __construct(
		private Environment $env,
	) {}

	public function create(Database $db): int
	{
		$env = $this->env;

		if ($env->checkIfMigrationsTableExists($db)) {
			echo "Table '{$env->table}' already exists. Aborting\n";

			return 1;
		}

		$ddl = $env->getMigrationsTableDDL();

		if ($ddl !== false) {
			try {
				$db->execute($ddl)->run();
				echo "\033[1;32mSuccess\033[0m: Created table '{$env->table}'\n";

				return 0;

				// Would require to create additional errornous DDL or to
				// setup a different test database. Too much effort.
				// @codeCoverageIgnoreStart
			} catch (Throwable $e) {
				echo "\033[1;31mError\033[0m: While trying to create table '{$env->table}'\n";
				echo $e->getMessage() . PHP_EOL;

				if ($env->showStacktrace) {
					echo escapeshellarg($e->getTraceAsString()) . PHP_EOL;
				}

				return 1;

				// @codeCoverageIgnoreEnd
			}
		}

		// Cannot be reliably tested.
		// Would require an unsupported driver to be installed.
		// @codeCoverageIgnoreStart
		echo "PDO driver '{$env->driver}' not supported. Aborting\n";

		return 1;

		// @codeCoverageIgnoreEnd
	}
}
