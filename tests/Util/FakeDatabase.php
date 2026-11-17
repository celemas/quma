<?php

declare(strict_types=1);

namespace Celema\Quma\Tests\Util;

use Celema\Quma\Connection;
use Celema\Quma\Database;
use Celema\Quma\Query;

final class FakeDatabase extends Database
{
	public function __construct(
		Connection $conn,
		private readonly string $driver,
		private readonly int $available,
		/** @var list<mixed>|null The row one() returns instead of the available count. */
		private readonly ?array $row = null,
	) {
		parent::__construct($conn);
	}

	public function getPdoDriver(): string
	{
		return $this->driver;
	}

	public function execute(string $query, mixed ...$args): Query
	{
		return new FakeQuery($this->available, $this->row);
	}
}
