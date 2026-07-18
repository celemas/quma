<?php

declare(strict_types=1);

namespace Celema\Quma\Hydration;

use BackedEnum;

/** @internal */
final readonly class NamedTypeMetadata
{
	/**
	 * @param non-empty-string $name
	 * @param class-string|null $class
	 * @param 'int'|'float'|'bool'|'string'|null $scalar
	 * @param 'immutable'|'mutable'|null $date
	 * @param class-string<BackedEnum>|null $enum
	 */
	// @mago-expect lint:excessive-parameter-list Metadata mirrors the normalized named type shape.
	public function __construct(
		public string $name,
		public bool $builtin,
		public ?string $class,
		public ?string $scalar,
		public ?string $date,
		public ?string $enum,
	) {}

	public function describe(): string
	{
		return $this->builtin ? $this->name : $this->class ?? $this->name;
	}
}
