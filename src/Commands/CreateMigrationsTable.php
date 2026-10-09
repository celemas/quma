<?php

declare(strict_types=1);

namespace Celema\Quma\Commands;

use Celema\Console\Command;
use Celema\Console\Io;
use Celema\Console\Opt;
use Celema\Quma\Connection;
use Celema\Quma\Environment;
use Celema\Quma\Migrations\MetadataTable;

#[Command('db:create-migrations-table', 'Creates a migrations table', group: 'Database')]
final class CreateMigrationsTable
{
	/** @param array<non-empty-string, Connection>|Connection $connections */
	public function __construct(
		private readonly array|Connection $connections,
		private readonly array $options = [],
	) {}

	public function __invoke(
		Io $io,
		#[Opt('Connection to use', value: 'name')]
		string $conn = 'default',
		#[Opt('Show stack traces for failing table creation')]
		bool $stacktrace = false,
	): int {
		$env = new Environment($this->connections, $this->options, $conn, $stacktrace);

		return new MetadataTable($env, $io)->create($env->db);
	}
}
