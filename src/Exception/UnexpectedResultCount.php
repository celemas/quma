<?php

declare(strict_types=1);

namespace Celema\Quma\Exception;

use RuntimeException;

/** @api */
final class UnexpectedResultCount extends RuntimeException
{
	public static function none(): self
	{
		return new self('Expected exactly one result, got none.');
	}

	public static function multiple(): self
	{
		return new self('Expected exactly one result, got more than one.');
	}
}
