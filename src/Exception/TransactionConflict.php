<?php

declare(strict_types=1);

namespace Celema\Quma\Exception;

/**
 * A lock or a concurrent transaction stopped the statement; it may succeed when repeated.
 *
 * @api
 */
final class TransactionConflict extends QueryFailed {}
