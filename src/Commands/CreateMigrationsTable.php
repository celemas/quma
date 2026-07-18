<?php

declare(strict_types=1);

namespace Celema\Quma\Commands;

use Celema\Console\Args;
use Celema\Quma\Migrations\MetadataTable;
use Override;

final class CreateMigrationsTable extends Command
{
	protected string $name = 'create-migrations-table';
	protected string $group = 'Database';
	protected string $prefix = 'db';
	protected string $description = 'Creates a migrations table';

	#[Override]
	public function run(Args $args): int
	{
		$env = $this->env;

		return new MetadataTable($env)->create($env->db);
	}
}
