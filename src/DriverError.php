<?php

declare(strict_types=1);

namespace Celema\Quma;

use Celema\Quma\Exception\CheckViolation;
use Celema\Quma\Exception\ConnectionLost;
use Celema\Quma\Exception\DataError;
use Celema\Quma\Exception\ExclusionViolation;
use Celema\Quma\Exception\ForeignKeyViolation;
use Celema\Quma\Exception\IntegrityViolation;
use Celema\Quma\Exception\NotNullViolation;
use Celema\Quma\Exception\QueryFailed;
use Celema\Quma\Exception\TransactionConflict;
use Celema\Quma\Exception\UniqueViolation;
use PDOException;

/**
 * Reads a driver's exception. SQLSTATE alone does not tell failures apart: MySQL
 * reports 23000 for most constraints and HY000 for others, SQLite 23000 for all,
 * and a lost PostgreSQL connection is an HY000 known by its message.
 *
 * @internal
 */
final class DriverError
{
	private const array LOST_POSTGRES = [
		'no connection to the server',
		'server closed the connection',
		'terminating connection',
		'connection has been closed',
	];

	private readonly string $sqlState;
	private readonly int $code;
	private readonly string $message;

	public function __construct(
		private readonly string $driver,
		PDOException $e,
	) {
		$info = $e->errorInfo ?? [];
		$this->sqlState = (string) ($info[0] ?? $e->getCode());
		$this->code = (int) ($info[1] ?? 0);
		$this->message = (string) ($info[2] ?? $e->getMessage());
	}

	/** @return class-string<QueryFailed> */
	public function exception(): string
	{
		return match ($this->driver) {
			'mysql' => $this->mysql(),
			'sqlite' => $this->sqlite(),
			default => $this->standard(),
		};
	}

	public function constraint(): ?string
	{
		$pattern = match ($this->driver) {
			'mysql' => "/(?|for key '(?:[^'.]*\\.)?([^']+)'|CONSTRAINT `([^`]+)`|Check constraint '([^']+)')/",
			'sqlite' => '/constraint failed: (.+)$/',
			default => '/constraint "([^"]+)"/',
		};

		if (preg_match($pattern, $this->message, $matches) !== 1) {
			return null;
		}

		return $matches[1];
	}

	/** @return class-string<QueryFailed> */
	private function standard(): string
	{
		return match ($this->sqlState) {
			'23505' => UniqueViolation::class,
			'23001', '23503' => ForeignKeyViolation::class,
			'23502' => NotNullViolation::class,
			'23514' => CheckViolation::class,
			'23P01' => ExclusionViolation::class,
			'40001', '40P01', '55P03' => TransactionConflict::class,
			'57P01', '57P02', '57P03' => ConnectionLost::class,
			default => $this->standardClass(),
		};
	}

	/** @return class-string<QueryFailed> */
	private function standardClass(): string
	{
		return match (substr($this->sqlState, 0, 2)) {
			'23' => IntegrityViolation::class,
			'22' => DataError::class,
			'08' => ConnectionLost::class,
			default => $this->isLostPostgres() ? ConnectionLost::class : QueryFailed::class,
		};
	}

	private function isLostPostgres(): bool
	{
		return array_any(self::LOST_POSTGRES, fn(string $text): bool => str_contains($this->message, $text));
	}

	/** @return class-string<QueryFailed> */
	private function mysql(): string
	{
		return match ($this->code) {
			1062, 1169, 1586 => UniqueViolation::class,
			1216, 1217, 1451, 1452 => ForeignKeyViolation::class,
			1048, 1364 => NotNullViolation::class,
			3819, 4025 => CheckViolation::class,
			1264, 1265, 1292, 1366, 1406 => DataError::class,
			1205, 1213 => TransactionConflict::class,
			1053, 1927, 2006, 2013, 2055 => ConnectionLost::class,
			default => $this->standardClass(),
		};
	}

	/** @return class-string<QueryFailed> */
	private function sqlite(): string
	{
		return match (true) {
			str_starts_with($this->message, 'UNIQUE constraint') => UniqueViolation::class,
			str_starts_with($this->message, 'FOREIGN KEY constraint') => ForeignKeyViolation::class,
			str_starts_with($this->message, 'NOT NULL constraint') => NotNullViolation::class,
			str_starts_with($this->message, 'CHECK constraint') => CheckViolation::class,
			str_starts_with($this->message, 'cannot store'), $this->code === 20 => DataError::class,
			$this->code === 5, $this->code === 6 => TransactionConflict::class,
			default => $this->standardClass(),
		};
	}
}
