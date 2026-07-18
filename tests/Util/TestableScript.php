<?php

declare(strict_types=1);

namespace Celema\Quma\Tests\Util;

use Celema\Quma\Args;
use Celema\Quma\Script;

final class TestableScript extends Script
{
	public function evaluateTemplatePublic(string $path, Args $args): string
	{
		return $this->evaluateTemplate($path, $args);
	}
}
