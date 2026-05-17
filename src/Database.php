<?php

declare(strict_types=1);

namespace Celemas\Quma;

use PDO;
use RuntimeException;
use Throwable;

/** @api */
class Database
{
	protected const int TEMPLATE_CACHE_VERSION = 1;

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

		foreach ($this->conn->sql() as $path) {
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
		return $this->conn->fetchMode();
	}

	public function connected(): bool
	{
		return $this->pdo !== null;
	}

	public function getPdoDriver(): string
	{
		return $this->conn->driver();
	}

	public function getSqlDirs(): array
	{
		return $this->conn->sql();
	}

	public function loadScript(string $path, bool $isTemplate): LoadedScript
	{
		$key = ($isTemplate ? 'tpql:' : 'sql:') . $path;

		if (array_key_exists($key, $this->compiledScripts)) {
			return $this->compiledScripts[$key];
		}

		$source = file_get_contents($path);

		if ($source === false) {
			throw new RuntimeException('Could not read SQL script: ' . $path);
		}

		$compiled = $this->conn->applyPlaceholders($source, $path, $isTemplate);
		$cachePath = $isTemplate ? $this->cacheTemplate($path, $compiled) : null;
		$script = new LoadedScript($compiled, $path, $cachePath);
		$this->compiledScripts[$key] = $script;

		return $script;
	}

	public function assertNoTemplatePlaceholders(string $source, string $path): void
	{
		$this->conn->assertNoTemplatePlaceholders($source, $path);
	}

	protected function cacheTemplate(string $sourcePath, string $source): ?string
	{
		$cacheDir = $this->conn->cacheDir();

		if ($cacheDir === null) {
			return null;
		}

		$cachePath = $this->templateCachePath($sourcePath, $cacheDir);

		if (is_file($cachePath)) {
			return $cachePath;
		}

		$this->writeTemplateCache($sourcePath, $source, $cacheDir, $cachePath);

		return $cachePath;
	}

	/** @param non-empty-string $cacheDir */
	protected function templateCachePath(string $sourcePath, string $cacheDir): string
	{
		$modifiedAt = filemtime($sourcePath);
		$size = filesize($sourcePath);

		if ($modifiedAt === false || $size === false) {
			throw new RuntimeException('Could not read SQL template metadata: ' . $sourcePath); // @codeCoverageIgnore
		}

		$key = json_encode([
			'version' => self::TEMPLATE_CACHE_VERSION,
			'path' => $sourcePath,
			'driver' => $this->conn->driver(),
			'delimiters' => $this->conn->placeholderDelimiters()?->values(),
			'placeholders' => $this->conn->placeholderValues(),
			'modifiedAt' => $modifiedAt,
			'size' => $size,
		], JSON_THROW_ON_ERROR);

		return $cacheDir . DIRECTORY_SEPARATOR . 'tpql-' . hash('xxh128', $key) . '.php';
	}

	/**
	 * @codeCoverageIgnore
	 * @param non-empty-string $cacheDir
	 **/
	protected function writeTemplateCache(
		string $sourcePath,
		string $source,
		string $cacheDir,
		string $cachePath,
	): void {
		$tmp = tempnam($cacheDir, 'tpql-');

		if ($tmp === false) {
			throw new RuntimeException('Could not create compiled TPQL cache file in ' . $cacheDir);
		}

		try {
			if (file_put_contents($tmp, $source, LOCK_EX) === false) {
				throw new RuntimeException(
					"Could not write compiled TPQL cache file for {$sourcePath} to {$cachePath}",
				);
			}

			if (is_file($cachePath)) {
				return;
			}

			if (!rename($tmp, $cachePath) && !is_file($cachePath)) {
				throw new RuntimeException(
					"Could not write compiled TPQL cache file for {$sourcePath} to {$cachePath}",
				);
			}
		} finally {
			if (is_file($tmp)) {
				unlink($tmp);
			}
		}
	}

	public function connect(): static
	{
		if ($this->pdo !== null) {
			return $this;
		}

		$conn = $this->conn;

		$pdo = new PDO(
			$conn->dsn(),
			$conn->username(),
			$conn->password(),
			$conn->pdoOptions(),
		);

		// Always throw an exception when an error occures
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		// Allow getting the number of rows
		$pdo->setAttribute(PDO::ATTR_CURSOR, PDO::CURSOR_SCROLL);
		// deactivate native prepared statements by default
		$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
		// do not alter casing of the columns from sql
		$pdo->setAttribute(PDO::ATTR_CASE, PDO::CASE_NATURAL);

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
