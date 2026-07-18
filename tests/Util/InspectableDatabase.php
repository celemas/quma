<?php

declare(strict_types=1);

namespace Celema\Quma\Tests\Util;

use Celema\Quma\Database;
use PDO;

final class InspectableDatabase extends Database
{
	private bool $connectDisabled = false;

	public function connectedAtPublic(): ?int
	{
		return $this->connectedAt;
	}

	public function lastUsedAtPublic(): ?int
	{
		return $this->lastUsedAt;
	}

	public function setPdoPublic(PDO $pdo): void
	{
		$this->pdo = $pdo;
	}

	public function disableConnect(): void
	{
		$this->connectDisabled = true;
	}

	public function connect(): static
	{
		if ($this->connectDisabled) {
			return $this;
		}

		return parent::connect();
	}
}
