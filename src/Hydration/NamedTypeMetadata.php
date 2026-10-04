<?php

declare(strict_types=1);

namespace Celema\Quma\Hydration;

use BackedEnum;

/** @internal */
final readonly class NamedTypeMetadata
{
	/**
	 * @param non-empty-string $name The scalar type or the class name
	 * @param 'int'|'float'|'bool'|'string'|null $scalar
	 * @param 'immutable'|'mutable'|null $date
	 * @param class-string<BackedEnum>|null $enum
	 */
	public function __construct(
		public string $name,
		public ?string $scalar,
		public ?string $date,
		public ?string $enum,
	) {}

	public function describe(): string
	{
		return $this->name;
	}
}
