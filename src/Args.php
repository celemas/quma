<?php

declare(strict_types=1);

namespace Celema\Quma;

use InvalidArgumentException;

/**
 * @psalm-type ArgsArray = list<mixed>|array<string, mixed>
 */
final class Args
{
	private ArgType $type;
	private int $count;

	/** @var list<mixed> */
	private readonly array $positional;

	/** @var array<string, mixed> */
	private readonly array $named;

	/** @param array<array-key, mixed> $args */
	public function __construct(array $args)
	{
		$this->count = count($args);
		$values = $this->count === 1 && array_key_exists(0, $args) && is_array($args[0]) ? $args[0] : $args;

		if (array_is_list($values)) {
			$this->type = ArgType::Positional;
			$this->positional = $values;
			$this->named = [];

			return;
		}

		$names = [];

		foreach (array_keys($values) as $key) {
			if (is_int($key)) {
				throw new InvalidArgumentException(
					'Query arguments must be either a list of positional values or named values with string keys',
				);
			}

			$names[] = $key;
		}

		$this->type = ArgType::Named;
		$this->positional = [];
		$this->named = array_combine($names, $values);
	}

	/** @psalm-return ArgsArray */
	public function get(): array
	{
		return $this->type === ArgType::Named ? $this->named : $this->positional;
	}

	/** @return array<string, mixed> */
	public function getNamed(): array
	{
		return $this->named;
	}

	public function count(): int
	{
		return $this->count;
	}

	public function type(): ArgType
	{
		return $this->type;
	}
}
