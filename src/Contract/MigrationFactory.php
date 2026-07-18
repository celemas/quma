<?php

declare(strict_types=1);

namespace Celema\Quma\Contract;

use Celema\Quma\Environment;

/** @api */
interface MigrationFactory
{
	/** @param class-string<Migration> $class */
	public function create(string $class, Environment $env): Migration;
}
