<?php

declare(strict_types=1);

namespace Celema\Quma;

use InvalidArgumentException;

/** @api */
final class Delimiters
{
	public const string COMMENT_OPEN = '/*:';
	public const string COMMENT_CLOSE = ':*/';
	public const string BRACKET_OPEN = '[::';
	public const string BRACKET_CLOSE = '::]';

	public function __construct(
		public readonly string $open,
		public readonly string $close,
	) {
		$this->validate('opening', $this->open);
		$this->validate('closing', $this->close);
	}

	public static function comments(): self
	{
		return new self(self::COMMENT_OPEN, self::COMMENT_CLOSE);
	}

	public static function brackets(): self
	{
		return new self(self::BRACKET_OPEN, self::BRACKET_CLOSE);
	}

	/** @return array{open: string, close: string} */
	public function values(): array
	{
		return [
			'open' => $this->open,
			'close' => $this->close,
		];
	}

	public function token(string $name): string
	{
		return $this->open . $name . $this->close;
	}

	private function validate(string $label, string $delimiter): void
	{
		if ($delimiter === '') {
			throw new InvalidArgumentException("Static placeholder {$label} delimiter must not be empty.");
		}

		if (str_contains($delimiter, "\0")) {
			throw new InvalidArgumentException(
				"Static placeholder {$label} delimiter must not contain NUL bytes.",
			);
		}
	}
}
