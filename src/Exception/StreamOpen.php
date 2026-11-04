<?php

declare(strict_types=1);

namespace Celema\Quma\Exception;

use RuntimeException;

/** @api */
final class StreamOpen extends RuntimeException
{
	public function __construct()
	{
		parent::__construct(
			'Another statement cannot run while a stream is open on the connection. '
				. 'End the loop first, or use lazy() or a second Database.',
		);
	}
}
