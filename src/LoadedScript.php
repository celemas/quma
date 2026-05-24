<?php

declare(strict_types=1);

namespace Celemas\Quma;

use Closure;

final class LoadedScript
{
	/** @var (Closure(string, string): string)|null */
	public readonly ?Closure $compile;

	/** @param (Closure(string, string): string)|null $compile */
	public function __construct(
		public readonly string $source,
		public readonly string $sourcePath,
		?Closure $compile = null,
	) {
		$this->compile = $compile;
	}
}
