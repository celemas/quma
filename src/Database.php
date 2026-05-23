<?php

declare(strict_types=1);

namespace Celemas\Quma;

use Closure;
use PDO;
use RuntimeException;
use Throwable;

/** @api */
class Database
{
	public readonly bool $debug;
	protected ?PDO $pdo = null;
	protected ?int $connectedAt = null;
	protected ?int $lastUsedAt = null;
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

		$this->pdo = null;
		$this->connectedAt = null;
		$this->lastUsedAt = null;
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

	public function reset(): void
	{
		if ($this->pdo === null) {
			return;
		}

		if ($this->pdo->inTransaction()) {
			$this->pdo->rollBack();
		}

		$this->touchConnection();
	}

	public function quote(string $value): string
	{
		return $this->requirePdo()->quote($value);
	}

	public function begin(): bool
	{
		return $this->requirePdo()->beginTransaction();
	}

	public function commit(): bool
	{
		return $this->requirePdo()->commit();
	}

	public function rollback(): bool
	{
		return $this->requirePdo()->rollback();
	}

	public function getConn(): PDO
	{
		return $this->requirePdo();
	}

	protected function requirePdo(): PDO
	{
		$this->connect();

		if ($this->pdo !== null) {
			$this->touchConnection();

			return $this->pdo;
		}

		throw new RuntimeException('Database connection not initialized');
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
