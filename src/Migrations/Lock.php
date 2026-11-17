<?php

declare(strict_types=1);

namespace Celema\Quma\Migrations;

use Celema\Quma\Database;
use Celema\Quma\Environment;
use Pdo\Sqlite;
use RuntimeException;

/**
 * Keeps concurrent runs on the same migrations table apart, such as those
 * of several containers that start at once: a run waits until the other
 * one finished, then reads which migrations are applied.
 */
final readonly class Lock
{
	private const string MYSQL_NAME = "CONCAT('quma:', SHA1(CONCAT(DATABASE(), '.', ?)))";

	public function __construct(
		private Environment $env,
	) {}

	/** Takes the lock, and begins the run's transaction where the driver has one. */
	public function begin(Database $db): void
	{
		match ($db->getPdoDriver()) {
			'sqlite' => $this->beginImmediately($db),
			'pgsql' => $this->beginLocked($db),
			'mysql' => $db->execute('SELECT GET_LOCK(' . self::MYSQL_NAME . ', -1)', $this->env->table)->run(),
			default => throw new RuntimeException('Database driver not supported'),
		};
	}

	/** Releases a lock that does not end with the transaction. */
	public function release(Database $db): void
	{
		if ($db->getPdoDriver() === 'mysql') {
			$db->execute('SELECT RELEASE_LOCK(' . self::MYSQL_NAME . ')', $this->env->table)->run();
		}
	}

	/**
	 * SQLite has no advisory locks, but an immediate transaction takes the
	 * write lock right away, before the run reads the applied migrations.
	 */
	private function beginImmediately(Database $db): void
	{
		$pdo = $db->getConn();
		$mode = $pdo->getAttribute(Sqlite::ATTR_TRANSACTION_MODE);
		$pdo->setAttribute(Sqlite::ATTR_TRANSACTION_MODE, Sqlite::TRANSACTION_MODE_IMMEDIATE);

		try {
			$db->begin();
		} finally {
			$pdo->setAttribute(Sqlite::ATTR_TRANSACTION_MODE, $mode);
		}
	}

	private function beginLocked(Database $db): void
	{
		$db->begin();
		// A stricter default would read the applied migrations from a snapshot taken before the lock.
		$db->execute('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')->run();
		$db->execute('SELECT pg_advisory_xact_lock(?)', crc32('quma:' . $this->env->table))->run();
	}
}
