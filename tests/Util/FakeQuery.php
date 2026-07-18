<?php

declare(strict_types=1);

namespace Celema\Quma\Tests\Util;

use Celema\Quma\Query;
use Closure;

final class FakeQuery extends Query
{
	public function __construct(
		private readonly int $available,
	) {}

	public function one(string|Closure|null $map = null, ?int $fetchMode = null): array|object
	{
		return ['available' => $this->available];
	}

	public function first(string|Closure|null $map = null, ?int $fetchMode = null): array|object|null
	{
		return ['available' => $this->available];
	}

	public function fetch(string|Closure|null $map = null, ?int $fetchMode = null): array|object|null
	{
		return ['available' => $this->available];
	}
}
