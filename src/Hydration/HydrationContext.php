<?php

declare(strict_types=1);

namespace Celema\Quma\Hydration;

/** @internal */
final readonly class HydrationContext
{
	/**
	 * @param class-string $class
	 * @param non-empty-string $parameter
	 * @param non-empty-string $column
	 * @param list<string> $rowKeys
	 */
	public function __construct(
		public string $class,
		public string $parameter,
		public string $column,
		public ?string $sourcePath,
		public array $rowKeys,
	) {}
}
