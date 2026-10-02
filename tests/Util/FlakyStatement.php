<?php

declare(strict_types=1);

namespace Celema\Quma\Tests\Util;

use PDO;
use PDOStatement;

/** A statement of a FlakyPdo connection. */
final class FlakyStatement extends PDOStatement
{
	protected function __construct(
		private readonly FlakyPdo $pdo,
	) {}

	public function execute(?array $params = null): bool
	{
		$this->pdo->roundTrip();
		$result = parent::execute($params);
		$this->pdo->executed();

		return $result;
	}

	public function fetch(
		int $mode = PDO::FETCH_DEFAULT,
		int $cursorOrientation = PDO::FETCH_ORI_NEXT,
		int $cursorOffset = 0,
	): mixed {
		$this->pdo->roundTrip();

		return parent::fetch($mode, $cursorOrientation, $cursorOffset);
	}

	public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
	{
		$this->pdo->roundTrip();

		return parent::fetchAll($mode, ...$args);
	}
}
