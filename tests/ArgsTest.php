<?php

declare(strict_types=1);

namespace Celema\Quma\Tests;

use Celema\Quma\Args;
use Celema\Quma\ArgType;

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
}
