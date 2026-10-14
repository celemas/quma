<?php

declare(strict_types=1);

namespace Celema\Quma\Tests;

use Celema\Quma\Args;
use Celema\Quma\ArgType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
class ArgsTest extends TestCase
{
	public function testGetNamedReturnsEmptyArrayForPositionalArgs(): void
	{
		$args = new Args([[1, 2]]);

		$this->assertSame(ArgType::Positional, $args->type());
		$this->assertSame([], $args->getNamed());
	}

	public function testGetNamedReturnsInputForNamedArgs(): void
	{
		$args = new Args([['member' => 1]]);

		$this->assertSame(ArgType::Named, $args->type());
		$this->assertSame(['member' => 1], $args->getNamed());
	}

	public function testPhpNamedArgumentsAreNamedArgs(): void
	{
		$args = new Args(['member' => 1, 'ids' => [2, 3]]);

		$this->assertSame(ArgType::Named, $args->type());
		$this->assertSame(['member' => 1, 'ids' => [2, 3]], $args->get());
		$this->assertSame(2, $args->count());
	}

	/** @param array<array-key, mixed> $args */
	#[DataProvider('invalidArgs')]
	public function testRejectsArgsThatAreNeitherPositionalNorNamed(array $args): void
	{
		$this->expectException(InvalidArgumentException::class);

		new Args($args);
	}

	/** @return array<string, list<array<array-key, mixed>>> */
	public static function invalidArgs(): array
	{
		return [
			'positional and named arguments' => [[1, 'member' => 2]],
			'array with positional and named keys' => [[[1, 'member' => 2]]],
			'array with gaps in its positional keys' => [[[1 => 2]]],
		];
	}
}
