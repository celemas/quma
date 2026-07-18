<?php

declare(strict_types=1);

namespace Celema\Quma\Hydration;

/** @internal */
interface MetadataCache
{
	/** @param class-string $class */
	public function metadata(string $class): ClassMetadata;
}
