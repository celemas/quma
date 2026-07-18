<?php

declare(strict_types=1);

namespace Celema\Quma\Contract;

use Celema\Quma\Environment;

/** @api */
interface Migration
{
	public function run(Environment $env): void;
}
