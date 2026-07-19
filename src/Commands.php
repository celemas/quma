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
		// Factories keep registration cheap: no Environment is built unless
		// the command actually runs.
		return new BaseCommands([
			Add::class => static fn(): Add => new Add($conn, $options),
			CreateMigrationsTable::class => static fn(): CreateMigrationsTable => new CreateMigrationsTable(
				$conn,
				$options,
			),
			Migrations::class => static fn(): Migrations => new Migrations(
				$conn,
				$options,
				$migrationFactory,
			),
		]);
	}
}
