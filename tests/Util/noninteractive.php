<?php

declare(strict_types=1);

namespace Celemas\Quma\Commands;

function stream_isatty(mixed $stream): bool
{
	return false;
}
