<?php

declare(strict_types=1);

namespace Celema\Quma\Tests\Util;

use PDO;
use PDOException;
use PDOStatement;

/** A connection whose server went away: every round trip fails. */
final class BrokenPdo extends PDO
{
	public function __construct(
		private readonly bool $inTransaction = false,
	) {
		parent::__construct('sqlite::memory:');
	}

	public function inTransaction(): bool
	{
		return $this->inTransaction;
	}

	public function rollBack(): bool
	{
		throw new PDOException('server closed the connection unexpectedly');
	}

	public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
	{
		throw new PDOException('server closed the connection unexpectedly');
	}
}
