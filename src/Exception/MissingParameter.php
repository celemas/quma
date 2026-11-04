<?php

declare(strict_types=1);

namespace Celema\Quma\Exception;

use InvalidArgumentException;

/** @api */
final class MissingParameter extends InvalidArgumentException
{
	private function __construct(
		string $message,
		public readonly ?string $script,
	) {
		parent::__construct($message . ($script === null ? '.' : " in {$script}."));
	}

	/** @internal */
	public static function named(string $name, ?string $script): self
	{
		return new self("Missing parameter :{$name}", $script);
	}

	/** @internal */
	public static function positional(int $expected, int $given, ?string $script): self
	{
		return new self("Expected {$expected} positional parameters, got {$given}", $script);
	}
}
