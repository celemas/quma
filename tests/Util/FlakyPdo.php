<?php

declare(strict_types=1);

namespace Celema\Quma\Tests\Util;

use PDO;
use PDOException;
use PDOStatement;

/**
 * A connection that works until it is lost: from then on every round trip
 * fails, while the transaction state stays as it was, like on MySQL.
 */
final class FlakyPdo extends PDO
{
	private bool $lost = false;
	private bool $loseAfterExecute = false;

	public function __construct()
	{
		parent::__construct('sqlite::memory:', options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_STATEMENT_CLASS => [FlakyStatement::class, [$this]],
		]);
	}

	public function loseConnection(): void
	{
		$this->lost = true;
	}

	/** Loses the connection after the next statement ran, before its rows are fetched. */
	public function loseConnectionAfterExecute(): void
	{
		$this->loseAfterExecute = true;
	}

	public function roundTrip(): void
	{
		if ($this->lost) {
			throw new PDOException('MySQL server has gone away');
		}
	}

	public function executed(): void
	{
		$this->lost = $this->lost || $this->loseAfterExecute;
	}

	public function prepare(string $query, array $options = []): PDOStatement|false
	{
		$this->roundTrip();

		return parent::prepare($query, $options);
	}

	public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
	{
		$this->roundTrip();

		return parent::query($query, $fetchMode, ...$fetchModeArgs);
	}

	public function beginTransaction(): bool
	{
		$this->roundTrip();

		return parent::beginTransaction();
	}

	public function rollBack(): bool
	{
		$this->roundTrip();

		return parent::rollBack();
	}
}
