<?php

declare(strict_types=1);

namespace Celema\Quma;

use Celema\Console\Commands as BaseCommands;
use Celema\Quma\Commands\Add;
use Celema\Quma\Commands\CreateMigrationsTable;
use Celema\Quma\Commands\Migrations;
use Celema\Quma\Contract\MigrationFactory;

/** @api */
class Commands
{
	/** @param array<non-empty-string, Connection>|Connection $conn */
	public static function get(
		array|Connection $conn,
		array $options = [],
		?MigrationFactory $migrationFactory = null,
	): BaseCommands {
		return new BaseCommands([
			new Add($conn, $options),
			new CreateMigrationsTable($conn, $options),
			new Migrations($conn, $options, $migrationFactory),
		]);
	}
}
