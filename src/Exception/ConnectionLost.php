<?php

declare(strict_types=1);

namespace Celema\Quma\Exception;

/**
 * The connection broke. The next statement after a reset() opens a new one.
 *
 * @api
 */
final class ConnectionLost extends QueryFailed {}
