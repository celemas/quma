<?php

declare(strict_types=1);

namespace Celema\Quma;

use Attribute;

/** @api */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Column
{
	public function __construct(
		public readonly string $name,
	) {}
}
