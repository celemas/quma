<?php

declare(strict_types=1);

namespace Celema\Quma;

use Closure;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/** @api */
class Database
{
	public readonly bool $debug;
	protected ?PDO $pdo = null;
	protected ?int $connectedAt = null;
	protected ?int $lastUsedAt = null;
	/** Whether an operation failed on the connection since the last reset. */
	protected bool $suspect = false;
	/** @var array<string, LoadedScript> */
	protected array $compiledScripts = [];

	public function __construct(
		protected readonly Connection $conn,
	) {
		$this->debug = Debug::enabled();
	}

	public function __get(string $key): Folder
	{
		Util::assertPathSegment($key, 'SQL folder name');

		$exists = false;

		foreach ($this->conn->config->sql as $path) {
			$exists = is_dir($path . DIRECTORY_SEPARATOR . $key);

			if ($exists) {
				break;
			}
		}

		if (!$exists) {
			throw new RuntimeException('The SQL folder does not exist: ' . $key);
		}

		return new Folder($this, $key);
	}

	public function getFetchMode(): int
	{
		return $this->conn->config->pdo->fetchMode;
	}

	public function connected(): bool
	{
		return $this->pdo !== null;
	}

	public function getPdoDriver(): string
	{
		return $this->conn->config->driver;
	}

	public function getSqlDirs(): array
	{
		return $this->conn->config->sql;
	}

	public function loadScript(string $path, bool $isTemplate): LoadedScript
	{
		$key = ($isTemplate ? 'tpql:' : 'sql:') . $path;

		if (array_key_exists($key, $this->compiledScripts)) {
			return $this->compiledScripts[$key];
		}

		if ($isTemplate) {
			if (!is_readable($path)) {
				throw new RuntimeException('Could not read SQL script: ' . $path);
			}

			$script = new LoadedScript($path, $path, compile: $this->placeholderCompiler());
			$this->compiledScripts[$key] = $script;

			return $script;
		}

		$source = file_get_contents($path);

		if ($source === false) {
			throw new RuntimeException('Could not read SQL script: ' . $path);
		}

		$compiled = $this->compilePlaceholders($source, $path);
		$script = new LoadedScript($compiled, $path);
		$this->compiledScripts[$key] = $script;

		return $script;
	}

	/** @return Closure(string, string): string */
	private function placeholderCompiler(): Closure
	{
		return $this->compilePlaceholders(...);
	}

	private function compilePlaceholders(string $source, string $path): string
	{
		return $this->conn->config->placeholders?->compileSql($source, $path) ?? $source;
	}

	public function connect(): static
	{
		if ($this->pdo !== null) {
			return $this;
		}

		$conn = $this->conn;

		$pdo = new PDO(
			$conn->config->dsn,
			$conn->config->pdo->username,
			$conn->config->pdo->password,
			$conn->config->pdo->effectiveOptions(),
		);

		$this->pdo = $pdo;
		$this->markConnected();

		return $this;
	}

	public function disconnect(): void
	{
		if ($this->pdo !== null) {
			try {
				if ($this->pdo->inTransaction()) {
					$this->pdo->rollBack();
				}
			} catch (Throwable) {
				// @mago-expect lint:no-empty-catch-clause Rollback failures are intentionally ignored during teardown.
			}
		}

		$this->drop();
	}

	public function reconnect(): static
	{
		$this->disconnect();

		return $this->connect();
	}

	public function ping(): bool
	{
		if ($this->pdo === null) {
			return false;
		}

		try {
			$stmt = $this->pdo->query('SELECT 1');

			if ($stmt === false) {
				return false;
			}

			$this->touchConnection();

			return $stmt->fetchColumn() !== false;
		} catch (Throwable) {
			return false;
		}
	}

	/**
	 * Brings the connection back to a clean state after a unit of work, such
	 * as a request in a long-running worker: an open transaction is rolled
	 * back. A connection that cannot be rolled back is dropped instead of
	 * throwing; the next statement connects anew.
	 *
	 * If an operation failed during the unit of work, the connection is
	 * pinged and dropped if it is broken. The failure may have been an
	 * ordinary SQL error or a lost connection, and MySQL does not reflect a
	 * lost connection in its transaction state, so without the ping a
	 * connection used continuously would never be replaced.
	 *
	 * Returns whether an open connection is kept for reuse.
	 */
	public function reset(): bool
	{
		if ($this->pdo === null) {
			return false;
		}

		if ($this->rollBackOpenTransaction($this->pdo) && $this->checkAfterFailure()) {
			return true;
		}

		$this->drop();

		return false;
	}

	/**
	 * Pings the connection if an operation failed on it since the last
	 * reset. Returns whether the connection can be kept.
	 */
	private function checkAfterFailure(): bool
	{
		if (!$this->suspect) {
			return true;
		}

		$this->suspect = false;

		return $this->ping();
	}

	/** Returns false if the rollback failed. */
	private function rollBackOpenTransaction(PDO $pdo): bool
	{
		try {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
				// Only the rollback reached the server; a reset without one
				// must not hide a long idle period from the idle check.
				$this->touchConnection();
			}

			return true;
		} catch (Throwable) {
			return false;
		}
	}

	/**
	 * Records that an operation failed on the given connection, so that the
	 * next reset() verifies it. A query may still hold a connection that was
	 * replaced since; its failures do not concern the current one.
	 *
	 * @internal
	 */
	public function markSuspect(PDO $pdo): void
	{
		if ($pdo === $this->pdo) {
			$this->suspect = true;
		}
	}

	public function quote(string $value): string
	{
		return $this->requirePdo()->quote($value);
	}

	public function begin(): bool
	{
		return $this->transactionCall(static fn(PDO $pdo): bool => $pdo->beginTransaction());
	}

	public function commit(): bool
	{
		return $this->transactionCall(static fn(PDO $pdo): bool => $pdo->commit());
	}

	public function rollback(): bool
	{
		return $this->transactionCall(static fn(PDO $pdo): bool => $pdo->rollBack());
	}

	/** @param Closure(PDO): bool $call */
	private function transactionCall(Closure $call): bool
	{
		$pdo = $this->requirePdo();

		try {
			return $call($pdo);
		} catch (PDOException $e) {
			$this->markSuspect($pdo);

			throw $e;
		}
	}

	public function getConn(): PDO
	{
		return $this->requirePdo();
	}

	protected function requirePdo(): PDO
	{
		$this->checkReuse();
		$this->connect();

		if ($this->pdo !== null) {
			$this->touchConnection();

			return $this->pdo;
		}

		throw new RuntimeException('Database connection not initialized');
	}

	/**
	 * Before an existing connection is used after a pause, replace it if it
	 * reached the maximum age, or ping it if it was idle long enough and drop
	 * it if it is broken. Never inside a transaction: a new connection would
	 * silently lose the transaction's work, so a statement on a broken
	 * connection fails instead. Statements themselves are never retried.
	 */
	protected function checkReuse(): void
	{
		if ($this->pdo === null || $this->pdo->inTransaction()) {
			return;
		}

		$config = $this->conn->config;
		$now = time();

		if ($config->maxConnectionAge > 0 && ($now - ($this->connectedAt ?? $now)) >= $config->maxConnectionAge) {
			$this->drop();

			return;
		}

		if (
			$config->pingAfterIdle > 0
			&& ($now - ($this->lastUsedAt ?? $now)) >= $config->pingAfterIdle
			&& !$this->ping()
		) {
			$this->drop();
		}
	}

	protected function drop(): void
	{
		$this->pdo = null;
		$this->connectedAt = null;
		$this->lastUsedAt = null;
		$this->suspect = false;
	}

	protected function markConnected(): void
	{
		$now = time();
		$this->connectedAt = $now;
		$this->lastUsedAt = $now;
	}

	protected function touchConnection(): void
	{
		if ($this->pdo !== null) {
			$this->lastUsedAt = time();
		}
	}

	public function execute(string $query, mixed ...$args): Query
	{
		return new Query($this, $query, new Args($args), null);
	}
}
