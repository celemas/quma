<?php

declare(strict_types=1);

namespace Celema\Quma;

/**
 * Whether a database is isolated for a test, and whether the code under
 * test has a transaction of its own open inside the isolating one.
 *
 * @internal
 */
enum Isolation
{
	/** Not isolated: transactions are the connection's own. */
	case Off;

	/** Isolated, and the code under test has no transaction open. */
	case On;

	/** Isolated, and the code under test has its transaction open, a savepoint. */
	case Nested;
}
