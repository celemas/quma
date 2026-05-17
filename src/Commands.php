<?php

declare(strict_types=1);

namespace Celemas\Quma;

use Celemas\Cli\Commands as BaseCommands;
use Celemas\Quma\Commands\Add;
use Celemas\Quma\Commands\CreateMigrationsTable;
use Celemas\Quma\Commands\Migrations;
use Celemas\Quma\Contract\MigrationFactory;

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
