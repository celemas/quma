<?php

declare(strict_types=1);

namespace Celema\Quma\Tests;

use Celema\Quma\Util;

/**
 * @internal
 */
final class UtilTest extends TestCase
{
	public function testEmptyArrayIsNotAssociative(): void
	{
		$this->assertFalse(Util::isAssoc([]));
		$this->assertFalse(Util::isAssoc([1, 2]));
		$this->assertTrue(Util::isAssoc(['a' => 1]));
	}
}
