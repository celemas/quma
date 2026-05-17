<?php

declare(strict_types=1);

namespace Celemas\Quma\Contract;

use Celemas\Quma\Environment;

/** @api */
interface Migration
{
	public function run(Environment $env): void;
}
