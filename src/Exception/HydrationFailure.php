<?php

declare(strict_types=1);

namespace Celemas\Quma\Exception;

use RuntimeException;
use Throwable;

/** @api */
class HydrationFailure extends RuntimeException
{
	/**
	 * @param class-string $class
	 * @param list<string> $rowKeys
	 */
	public static function fromHydratableFailure(
		string $class,
		?string $sourcePath,
		array $rowKeys,
		Throwable $previous,
	): self {
		return new self(
			self::message(
				$class,
				$sourcePath,
				'Hydratable::fromRow() failed. Row keys: ' . self::formatRowKeys($rowKeys) . '.',
			),
			0,
			$previous,
		);
	}

	/** @param class-string $class */
	protected static function message(string $class, ?string $sourcePath, string $detail): string
	{
		return "Could not hydrate {$class} from " . self::source($sourcePath) . ": {$detail}";
	}

	protected static function source(?string $sourcePath): string
	{
		return $sourcePath ?? 'ad-hoc SQL';
	}

	/** @param list<string> $rowKeys */
	protected static function formatRowKeys(array $rowKeys): string
	{
		return $rowKeys === [] ? '(none)' : implode(', ', $rowKeys);
	}

	protected static function valueType(mixed $value): string
	{
		if (is_object($value)) {
			return $value::class;
		}

		if (is_resource($value)) {
			return get_resource_type($value) . ' resource';
		}

		return get_debug_type($value);
	}
}
