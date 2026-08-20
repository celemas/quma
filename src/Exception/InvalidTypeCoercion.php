<?php

declare(strict_types=1);

namespace Celema\Quma\Exception;

use Celema\Quma\Hydration\HydrationContext;
use Throwable;

/** @api */
final class InvalidTypeCoercion extends HydrationFailure
{
	/** @internal */
	public static function forContext(
		HydrationContext $context,
		mixed $value,
		string $type,
		string $reason,
	): self {
		return new self(self::message(
			$context->class,
			$context->sourcePath,
			"could not coerce column '{$context->column}' for parameter '\${$context->parameter}' to {$type}; "
				. 'value type: '
				. self::valueType($value)
				. ($reason === '' ? '' : "; {$reason}")
				. '. Row keys: '
				. self::formatRowKeys($context->rowKeys)
				. '.',
		));
	}

	/**
	 * @param class-string $class
	 * @param list<string> $rowKeys
	 */
	public static function constructorFailure(
		string $class,
		?string $sourcePath,
		array $rowKeys,
		Throwable $previous,
	): self {
		return new self(
			self::message(
				$class,
				$sourcePath,
				'constructor rejected hydrated arguments. Row keys: ' . self::formatRowKeys($rowKeys) . '.',
			),
			0,
			$previous,
		);
	}
}
