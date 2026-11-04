<?php

declare(strict_types=1);

namespace Celema\Quma;

use Celema\Quma\Exception\MissingParameter;
use Celema\Quma\Exception\QueryFailed;
use Celema\Quma\Exception\UnexpectedResultCount;
use Celema\Quma\Hydration\Hydrator;
use Closure;
use Generator;
use InvalidArgumentException;
use JsonException;
use PDO;
use Pdo\Mysql;
use PDOException;
use PDOStatement;
use RuntimeException;

/** @api */
class Query
{
	protected PDOStatement $stmt;
	protected bool $executed = false;
	private bool $ran = false;
	/** The rows the latest run read, if that run was a stream. */
	private ?int $streamed = null;
	protected ?Hydrator $hydrator = null;

	/** The connection the statement was prepared on. */
	private PDO $pdo;

	public function __construct(
		protected Database $db,
		protected string $query,
		protected Args $args,
		protected ?string $sourcePath = null,
	) {
		$this->expandLists();
		$this->assertParameters();
		$this->db->assertNoStream();
		$this->prepare($this->db->getConn());

		if ($this->db->debug) {
			Debug::query($this->db, $this->query, $this->args, $this->sourcePath);
		}
	}

	public function __toString(): string
	{
		return $this->interpolate();
	}

	/**
	 * @template T of object
	 *
	 * @param class-string<T>|Closure(array<string, mixed>):class-string<T>|null $map
	 * @return ($map is null ? array<array-key, mixed> : T)
	 */
	public function one(string|Closure|null $map = null, ?int $fetchMode = null): array|object
	{
		$fetchMode = $this->terminalOptions($map, $fetchMode);
		$this->executeFresh();

		try {
			$record = $this->fetchArrayRecord($fetchMode);

			if ($record === null) {
				throw UnexpectedResultCount::none();
			}

			if ($this->fetchArrayRecord($fetchMode) !== null) {
				throw UnexpectedResultCount::multiple();
			}

			return $this->hydrateRecord($record, $map);
		} finally {
			$this->stmt->closeCursor();
		}
	}

	/**
	 * @template T of object
	 *
	 * @param class-string<T>|Closure(array<string, mixed>):class-string<T>|null $map
	 * @return ($map is null ? array<array-key, mixed>|null : T|null)
	 */
	public function first(string|Closure|null $map = null, ?int $fetchMode = null): array|object|null
	{
		$fetchMode = $this->terminalOptions($map, $fetchMode);
		$this->executeFresh();

		try {
			$record = $this->fetchArrayRecord($fetchMode);

			return $record === null ? null : $this->hydrateRecord($record, $map);
		} finally {
			$this->stmt->closeCursor();
		}
	}

	/**
	 * @template T of object
	 *
	 * @param class-string<T>|Closure(array<string, mixed>):class-string<T>|null $map
	 * @return ($map is null ? array<array-key, mixed>|null : T|null)
	 */
	public function fetch(string|Closure|null $map = null, ?int $fetchMode = null): array|object|null
	{
		$fetchMode = $this->terminalOptions($map, $fetchMode);
		$this->executeForFetch();

		$record = $this->fetchArrayRecord($fetchMode);

		return $record === null ? null : $this->hydrateRecord($record, $map);
	}

	/**
	 * @template T of object
	 *
	 * @param class-string<T>|Closure(array<string, mixed>):class-string<T>|null $map
	 * @return ($map is null ? list<array<array-key, mixed>> : list<T>)
	 */
	public function all(string|Closure|null $map = null, ?int $fetchMode = null): array
	{
		$fetchMode = $this->terminalOptions($map, $fetchMode);
		$this->executeFresh();

		try {
			$records = $this->fetchAllRecords($fetchMode);

			if ($map === null) {
				return $records;
			}

			$result = [];

			foreach ($records as $record) {
				$result[] = $this->hydrator()->hydrate($record, $map, $this->sourcePath);
			}

			return $result;
		} finally {
			$this->stmt->closeCursor();
		}
	}

	/**
	 * @template T of object
	 *
	 * @param class-string<T>|Closure(array<string, mixed>):class-string<T>|null $map
	 * @return ($map is null
	 *     ? Generator<int, array<array-key, mixed>, mixed, void>
	 *     : Generator<int, T, mixed, void>)
	 */
	public function lazy(string|Closure|null $map = null, ?int $fetchMode = null): Generator
	{
		$fetchMode = $this->terminalOptions($map, $fetchMode);
		$this->executeFresh();

		try {
			while (($record = $this->fetchArrayRecord($fetchMode)) !== null) {
				if ($map === null) {
					yield $record;

					continue;
				}

				yield $this->hydrator()->hydrate($record, $map, $this->sourcePath);
			}
		} finally {
			$this->stmt->closeCursor();
		}
	}

	/**
	 * Lets the server send the rows while the loop runs, so a large result only
	 * needs the memory of one row. Other statements on the connection throw
	 * StreamOpen until the generator ends or is destroyed.
	 *
	 * @template T of object
	 *
	 * @param class-string<T>|Closure(array<string, mixed>):class-string<T>|null $map
	 * @return ($map is null
	 *     ? Generator<int, array<array-key, mixed>, mixed, void>
	 *     : Generator<int, T, mixed, void>)
	 */
	public function stream(string|Closure|null $map = null, ?int $fetchMode = null): Generator
	{
		$fetchMode = $this->terminalOptions($map, $fetchMode);
		$this->db->assertNoStream();
		$pdo = $this->db->getConn();
		$started = (int) hrtime(true);
		$stmt = $this->executeStream($pdo, $started);
		$this->db->openStream($stmt);
		$this->reportSuccess($started, null);
		$count = 0;

		try {
			while (($record = $this->fetchStreamRecord($pdo, $stmt, $fetchMode)) !== null) {
				$count++;

				if ($map === null) {
					yield $record;

					continue;
				}

				yield $this->hydrator()->hydrate($record, $map, $this->sourcePath);
			}
		} finally {
			$this->streamed = $count;

			if ($this->db->streaming($stmt)) {
				$this->db->closeStream();
			}
		}
	}

	private function executeStream(PDO $pdo, int $started): PDOStatement
	{
		$driver = $this->db->getPdoDriver();
		$prepared = $this->stmt;

		try {
			if ($driver === 'mysql') {
				$pdo->setAttribute(Mysql::ATTR_USE_BUFFERED_QUERY, false);
			}

			$this->stmt = $pdo->prepare($this->query, $driver === 'pgsql' ? [PDO::ATTR_PREFETCH => 0] : []);

			if ($this->args->count() > 0) {
				$this->bindArgs($this->args->get(), $this->args->type());
			}

			$this->stmt->execute();

			return $this->stmt;
		} catch (PDOException $e) {
			$this->fail($pdo, $e, $started);
		} finally {
			$this->stmt = $prepared;

			if ($driver === 'mysql') {
				$pdo->setAttribute(Mysql::ATTR_USE_BUFFERED_QUERY, true);
			}
		}
	}

	/** @return array<array-key, mixed>|null */
	private function fetchStreamRecord(PDO $pdo, PDOStatement $stmt, int $fetchMode): ?array
	{
		if (!$this->db->streaming($stmt)) {
			throw new RuntimeException('The stream ended when its connection was reset or closed.');
		}

		try {
			return $this->nullIfNot($stmt->fetch($fetchMode));
		} catch (PDOException $e) {
			$this->fail($pdo, $e);
		}
	}

	private function terminalOptions(string|Closure|null $map, ?int $fetchMode): int
	{
		$mode = $fetchMode ?? ($map === null ? $this->db->getFetchMode() : PDO::FETCH_ASSOC);

		if ($map !== null && $mode !== PDO::FETCH_ASSOC) {
			throw new InvalidArgumentException('Hydration requires PDO::FETCH_ASSOC.');
		}

		if (!in_array($mode, [PDO::FETCH_ASSOC, PDO::FETCH_NUM, PDO::FETCH_BOTH, PDO::FETCH_NAMED], true)) {
			throw new InvalidArgumentException(
				'Unsupported fetch mode. Use PDO::FETCH_ASSOC, PDO::FETCH_NUM, PDO::FETCH_BOTH, or PDO::FETCH_NAMED.',
			);
		}

		return $mode;
	}

	private function executeFresh(): void
	{
		$this->executeStatement();
		$this->executed = false;
	}

	private function executeForFetch(): void
	{
		if (!$this->executed) {
			$this->executeStatement();
			$this->executed = true;
		}
	}

	private function executeStatement(): bool
	{
		$this->db->assertNoStream();
		$this->useCurrentConnection();
		$started = (int) hrtime(true);

		try {
			$this->stmt->closeCursor();
			$result = $this->stmt->execute();
			$this->ran = true;
			$this->streamed = null;
		} catch (PDOException $e) {
			$this->fail($this->pdo, $e, $started);
		}

		$this->reportSuccess($started, $this->stmt->rowCount());

		return $result;
	}

	private function reportSuccess(int $started, ?int $rowCount): void
	{
		$this->db->report(new Execution($this->sourcePath, $this->query, $this->seconds($started), $rowCount, null));
	}

	private function seconds(int $started): float
	{
		return (float) ((int) hrtime(true) - $started) / 1e9;
	}

	/**
	 * Lets the database verify the connection before it is reused, as the
	 * failure may mean that the connection was lost. The failed operation
	 * itself is never retried.
	 */
	private function fail(PDO $pdo, PDOException $e, ?int $started = null): never
	{
		$this->db->markSuspect($pdo);
		$failure = QueryFailed::from($e, $this->db->getPdoDriver(), $this->sourcePath);

		if ($started !== null) {
			$this->db->report(new Execution($this->sourcePath, $this->query, $this->seconds($started), null, $failure));
		}

		throw $failure;
	}

	/**
	 * @template T of object
	 *
	 * @param array<array-key, mixed> $record
	 * @param class-string<T>|Closure(array<string, mixed>):class-string<T>|null $map
	 * @return ($map is null ? array<array-key, mixed> : T)
	 */
	private function hydrateRecord(array $record, string|Closure|null $map): array|object
	{
		if ($map === null) {
			return $record;
		}

		return $this->hydrator()->hydrate($record, $map, $this->sourcePath);
	}

	private function hydrator(): Hydrator
	{
		return $this->hydrator ??= Hydrator::default();
	}

	public function run(): bool
	{
		$this->executed = false;

		return $this->executeStatement();
	}

	public function len(): int
	{
		if ($this->streamed !== null) {
			return $this->streamed;
		}

		if ($this->ran) {
			return $this->stmt->rowCount();
		}

		$this->executeFresh();

		try {
			return $this->stmt->rowCount();
		} finally {
			$this->stmt->closeCursor();
		}
	}

	/**
	 * For debugging purposes only.
	 *
	 * Replaces any parameter placeholders in a query with the
	 * value of that parameter and returns the query as string.
	 *
	 * Covers most of the cases but is not perfect.
	 */
	public function interpolate(): string
	{
		return Debug::interpolate($this->query, $this->args, $this->db->getPdoDriver());
	}

	/** Replaces an array after IN, like `IN :ids`, with one placeholder per element. */
	private function expandLists(): void
	{
		$values = $this->args->getNamed();

		if (!array_any($values, static fn(mixed $value): bool => is_array($value))) {
			return;
		}

		$expanded = [];
		$query = preg_replace_callback(
			LiteralPattern::withLists($this->db->getPdoDriver()),
			/** @param array<array-key, string> $matches */
			function (array $matches) use ($values, &$expanded): string {
				$name = $matches['list'] ?? '';
				$list = $values[$name] ?? null;

				if (!is_array($list)) {
					return $matches[0];
				}

				$placeholders = $this->listPlaceholders($name, $list, $expanded);

				return (string) strstr($matches[0], ':', true) . '(' . implode(', ', $placeholders) . ')';
			},
			$this->query,
		);

		if ($query === null || $expanded === []) {
			return;
		}

		$this->query = $query;
		$this->args = new Args([array_replace($this->withoutReplacedLists($values, $query), $expanded)]);
	}

	/**
	 * @param array<array-key, mixed> $list
	 * @param array<string, mixed> $expanded
	 * @return non-empty-list<string>
	 */
	private function listPlaceholders(string $name, array $list, array &$expanded): array
	{
		if ($list === []) {
			throw new InvalidArgumentException("The list :{$name} after IN must not be empty.");
		}

		$elements = array_values($list);
		$keys = array_map(static fn(int $index): string => "{$name}__{$index}", array_keys($elements));

		foreach ($keys as $key) {
			if (array_key_exists($key, $this->args->getNamed())) {
				throw new InvalidArgumentException("The parameter :{$key} collides with the list :{$name}.");
			}
		}

		$expanded = array_replace($expanded, array_combine($keys, $elements));

		return array_map(static fn(string $key): string => ":{$key}", $keys);
	}

	/**
	 * PDO refuses a value without a placeholder, and a list may also be used as JSON elsewhere.
	 *
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>
	 */
	private function withoutReplacedLists(array $values, string $query): array
	{
		$code = preg_replace(LiteralPattern::for($this->db->getPdoDriver()), ' ', $query);
		preg_match_all('/(?<!:):([a-zA-Z_][a-zA-Z0-9_]*)/', (string) $code, $matches);
		$used = array_flip($matches[1]);

		return array_filter(
			$values,
			static fn(mixed $value, string $name): bool => !is_array($value) || array_key_exists($name, $used),
			ARRAY_FILTER_USE_BOTH,
		);
	}

	/**
	 * Without this check, SQLite binds NULL to a missing parameter and the
	 * other drivers report a syntax error at the placeholder.
	 */
	private function assertParameters(): void
	{
		$driver = $this->db->getPdoDriver();
		$code = preg_replace(LiteralPattern::for($driver), ' ', $this->query);

		// PDO parses the SQL again, so a statement too unusual to read here still runs or fails there.
		if ($code === null) {
			return;
		}

		$given = $this->args->get();

		if ($this->args->type() === ArgType::Named || $given === []) {
			// Text after a word or bracket is a PostgreSQL slice like a[1:n] when no values are bound.
			preg_match_all('/(?<![\w\]\)\[:]):([a-zA-Z_][a-zA-Z0-9_]*)/', $code, $matches);

			foreach ($matches[1] as $name) {
				if (!array_key_exists($name, $given)) {
					throw MissingParameter::named($name, $this->sourcePath);
				}
			}
		}

		if ($this->args->type() === ArgType::Named || $given === [] && $driver === 'pgsql') {
			// PostgreSQL's ? operators work as long as no values are bound.
			return;
		}

		$expected = (int) preg_match_all('/(?<!\?)\?(?!\?)/', $code);

		if ($expected > count($given)) {
			throw MissingParameter::positional($expected, count($given), $this->sourcePath);
		}
	}

	private function prepare(PDO $pdo): void
	{
		$started = (int) hrtime(true);

		try {
			$stmt = $pdo->prepare($this->query);
		} catch (PDOException $e) {
			$this->fail($pdo, $e, $started);
		}

		// Only a prepared statement switches the query to the connection;
		// otherwise its next execution would not prepare it again.
		$this->pdo = $pdo;
		$this->stmt = $stmt;

		if ($this->args->count() > 0) {
			$this->bindArgs($this->args->get(), $this->args->type());
		}
	}

	/**
	 * Before an execution, prepares the statement again if the database
	 * replaced its connection since, for example after the maximum age. The
	 * statement would otherwise still run on the old connection, outside a
	 * transaction begun on the new one.
	 */
	private function useCurrentConnection(): void
	{
		$pdo = $this->db->getConn();

		if ($pdo !== $this->pdo) {
			$this->prepare($pdo);
		}
	}

	/** @param array<array-key, mixed> $args */
	protected function bindArgs(array $args, ArgType $argType): void
	{
		array_walk(
			$args,
			function (mixed $value, int|string $index) use ($argType): void {
				if ($argType === ArgType::Named) {
					$arg = ':' . $index;
				} else {
					$arg = (int) $index + 1; // question mark placeholders are 1-indexed
				}

				$this->bindValue($arg, $value);
			},
		);
	}

	protected function bindValue(string|int $arg, mixed $value): void
	{
		switch (gettype($value)) {
			case 'boolean':
				$this->stmt->bindValue($arg, $value, PDO::PARAM_BOOL);

				break;

			case 'integer':
				$this->stmt->bindValue($arg, $value, PDO::PARAM_INT);

				break;

			case 'string':
				$this->stmt->bindValue($arg, $value, PDO::PARAM_STR);

				break;

			case 'NULL':
				$this->stmt->bindValue($arg, $value, PDO::PARAM_NULL);

				break;

			case 'array':
				try {
					$json = json_encode($value, JSON_THROW_ON_ERROR);
				} catch (JsonException $e) {
					throw new InvalidArgumentException(
						'Array parameters must be JSON-encodable.',
						previous: $e,
					);
				}

				$this->stmt->bindValue($arg, $json, PDO::PARAM_STR);

				break;

			default:
				throw new InvalidArgumentException(
					'Only the types bool, int, string, null and array are supported',
				);
		}
	}

	/** @return array<array-key, mixed>|null */
	protected function fetchArrayRecord(int $fetchMode): ?array
	{
		try {
			return $this->nullIfNot($this->stmt->fetch($fetchMode));
		} catch (PDOException $e) {
			$this->fail($this->pdo, $e);
		}
	}

	/** @return list<array<array-key, mixed>> */
	private function fetchAllRecords(int $fetchMode): array
	{
		try {
			/**
			 * @mago-expect lint:inline-variable-return Psalm makes this necessary
			 * @var list<array<array-key, mixed>> $records
			 */
			$records = $this->stmt->fetchAll($fetchMode);

			return $records;
		} catch (PDOException $e) {
			$this->fail($this->pdo, $e);
		}
	}

	/** @return array<array-key, mixed>|null */
	protected function nullIfNot(mixed $value): ?array
	{
		if (is_array($value)) {
			return $value;
		}

		return null;
	}
}
