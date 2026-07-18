<?php

declare(strict_types=1);

namespace Celema\Quma\Migrations;

use Closure;

final readonly class RunOptions
{
	/** @param Closure(): int $createMigrationsTable */
	public function __construct(
		public bool $showStacktrace,
		public bool $apply,
		public bool $tableExists,
		public Closure $createMigrationsTable,
	) {}
}
