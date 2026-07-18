<?php

declare(strict_types=1);

namespace Celema\Quma\Hydration;

/** @internal */
final readonly class ClassMetadata
{
	/**
	 * @param class-string $class
	 * @param list<ParameterMetadata>|null $parameters
	 */
	public function __construct(
		public string $class,
		public bool $hydratable,
		public bool $instantiable,
		public ?array $parameters,
	) {}
}
