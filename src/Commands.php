<?php

declare(strict_types=1);

namespace Celema\Quma;

use Celema\Quma\Commands\Add;
use Celema\Quma\Commands\CreateMigrationsTable;
use Celema\Quma\Commands\Migrations;
use Celema\Quma\Contract\MigrationFactory;
use Closure;

/** @api */
class Commands
{
	/**
	 * The migration commands as lazy factories, for a console Runner:
	 * `new Runner(Commands::get($conn))` or `$runner->add(...)`.
	 *
	 * @param array<non-empty-string, Connection>|Connection $conn
	 *
	 * @return array<class-string, Closure(): object>
	 */
	public static function get(
		array|Connection $conn,
		array $options = [],
		?MigrationFactory $migrationFactory = null,
	): array {
		return [
			Add::class => static fn(): Add => new Add($conn, $options),
			CreateMigrationsTable::class => static fn(): CreateMigrationsTable => new CreateMigrationsTable(
				$conn,
				$options,
			),
			Migrations::class => static fn(): Migrations => new Migrations($conn, $options, $migrationFactory),
		];
	}
}
