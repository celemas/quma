<?php

declare(strict_types=1);

namespace Celema\Quma\Commands;

use Celema\Console\Args;
use Celema\Console\Command;
use Celema\Console\Io;
use Celema\Quma\Connection;
use Celema\Quma\Environment;
use Celema\Quma\Migrations\MetadataTable;

#[Command('db:create-migrations-table', 'Creates a migrations table', group: 'Database')]
final class CreateMigrationsTable
{
	private readonly Environment $env;

	/** @param array<non-empty-string, Connection>|Connection $conn */
	public function __construct(array|Connection $conn, array $options = [])
	{
		$this->env = new Environment($conn, $options);
	}

	public function __invoke(Args $args, Io $io): int
	{
		$env = $this->env;

		return new MetadataTable($env)->create($env->db);
	}
}
