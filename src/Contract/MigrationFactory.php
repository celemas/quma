<?php

declare(strict_types=1);

namespace Celemas\Quma\Contract;

use Celemas\Quma\Environment;

/** @api */
interface MigrationFactory
{
	/** @param class-string<Migration> $class */
	public function create(string $class, Environment $env): Migration;
}
