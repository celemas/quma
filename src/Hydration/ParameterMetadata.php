<?php

declare(strict_types=1);

namespace Celema\Quma\Hydration;

/** @internal */
final readonly class ParameterMetadata
{
	/**
	 * @param non-empty-string $name
	 * @param non-empty-string $column
	 */
	// @mago-expect lint:excessive-parameter-list Metadata mirrors the normalized constructor parameter shape.
	public function __construct(
		public string $name,
		public string $column,
		public TypeMetadata $type,
		public bool $nullable,
		public bool $hasDefault,
		public mixed $defaultValue,
		public int $position,
	) {}
}
