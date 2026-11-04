<?php

declare(strict_types=1);

namespace Celema\Quma\Tests\Util;

final readonly class StreamedNumber
{
	public function __construct(
		public int $n,
	) {}
}
