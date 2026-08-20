<?php

declare(strict_types=1);

namespace Celema\Quma\Exception;

use Throwable;

/** @api */
final class InvalidHydrationTarget extends HydrationFailure
{
	/** @param list<string> $rowKeys */
	public static function forTarget(
		string $target,
		?string $sourcePath = null,
		array $rowKeys = [],
		string $reason = '',
		?Throwable $previous = null,
	): self {
		$message = "Invalid hydration target '{$target}' from " . self::source($sourcePath);

		if ($reason !== '') {
			$message .= ": {$reason}";
		}

		if ($rowKeys !== []) {
			$message .= '. Row keys: ' . self::formatRowKeys($rowKeys);
		}

		return new self($message . '.', 0, $previous);
	}

	/**
	 * @param class-string $class
	 * @param non-empty-string $parameter
	 */
	public static function forParameter(
		string $class,
		string $parameter,
		string $reason,
	): self {
		return new self("Invalid hydration target '{$class}': parameter '\${$parameter}' {$reason}.");
	}

	/** @param list<string> $rowKeys */
	public static function forClosureResult(
		mixed $result,
		?string $sourcePath,
		array $rowKeys,
	): self {
		return new self(
			'Invalid hydration target returned by resolver from '
				. self::source($sourcePath)
				. ': expected class-string, got '
				. self::valueType($result)
				. '. Row keys: '
				. self::formatRowKeys($rowKeys)
				. '.',
		);
	}
}
