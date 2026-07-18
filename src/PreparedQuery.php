<?php

declare(strict_types=1);

namespace Celema\Quma;

final class PreparedQuery
{
	/** @param array<non-empty-string, non-empty-string> $swaps */
	public function __construct(
		public readonly string $query,
		public readonly array $swaps,
	) {}
}
