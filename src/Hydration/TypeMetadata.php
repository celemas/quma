<?php

declare(strict_types=1);

namespace Celema\Quma\Hydration;

/** @internal */
final readonly class TypeMetadata
{
	/**
	 * @param 'named'|'union' $kind
	 * @param non-empty-list<NamedTypeMetadata> $names
	 */
	public function __construct(
		public string $kind,
		public bool $allowsNull,
		public array $names,
	) {}

	public function describe(): string
	{
		$names = array_map(
			static fn(NamedTypeMetadata $name): string => $name->describe(),
			$this->names,
		);

		if ($this->allowsNull) {
			$names[] = 'null';
		}

		return implode('|', $names);
	}
}
