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
#[Opt('--conn', 'Connection to use', value: 'name')]
#[Opt('--stacktrace', 'Show stack traces for failing table creation')]
final class CreateMigrationsTable
{
	private readonly Environment $env;

	/** @param array<non-empty-string, Connection>|Connection $conn */
	public function __construct(array|Connection $conn, array $options = [])
	{
		$this->env = new Environment($conn, $options);
	}

	public function __invoke(Io $io): int
	{
		$env = $this->env;

		return new MetadataTable($env, $io)->create($env->db);
	}
}
