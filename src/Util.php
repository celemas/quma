<?php

declare(strict_types=1);

namespace Celema\Quma;

use RuntimeException;

final class Util
{
	public static function assertPathSegment(string $segment, string $label): void
	{
		if (
			$segment === ''
			|| $segment === '.'
			|| $segment === '..'
			|| str_contains($segment, '/')
			|| str_contains($segment, '\\')
			|| str_contains($segment, "\0")
		) {
			$display = str_replace("\0", '\\0', $segment);

			throw new RuntimeException("Invalid {$label}: {$display}");
		}
	}

	public static function isAssoc(array $arr): bool
	{
		if ([] === $arr) {
			return false;
		}

		return array_keys($arr) !== range(0, count($arr) - 1);
	}
}
