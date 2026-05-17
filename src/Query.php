<?php

declare(strict_types=1);

namespace Celemas\Quma;

use Celemas\Quma\Hydration\Hydrator;
use Closure;
use Generator;
use InvalidArgumentException;
use JsonException;
use PDO;
use PDOStatement;

/** @api */
class Query
{
	// Matches multi line single and double quotes and handles \' \" escapes
	public const string PATTERN_STRING = '/([\'"])(?:\\\1|[\s\S])*?\1/';

	// PostgreSQL blocks delimited with $$
	public const string PATTERN_BLOCK = '/(\$\$)[\s\S]*?\1/';

	// Multi line comments /* */
	public const string PATTERN_COMMENT_MULTI = '/\/\*([\s\S]*?)\*\//';

	// Single line comments --
	public const string PATTERN_COMMENT_SINGLE = '/--.*$/m';

	protected PDOStatement $stmt;
	protected bool $executed = false;
	protected ?Hydrator $hydrator = null;

	public function __construct(
		protected Database $db,
		protected string $query,
		protected Args $args,
		protected ?string $sourcePath = null,
	) {
		$this->stmt = $this->db->getConn()->prepare($query);

		if ($args->count() > 0) {
			$this->bindArgs($args->get(), $args->type());
		}

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
		[$map, $fetchMode] = $this->terminalOptions($map, $fetchMode);
		$this->executeFresh();

		try {
			$record = $this->fetchArrayRecord($fetchMode);

			if ($record === null) {
				throw UnexpectedResultCountException::none();
			}

			if ($this->fetchArrayRecord($fetchMode) !== null) {
				throw UnexpectedResultCountException::multiple();
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
		[$map, $fetchMode] = $this->terminalOptions($map, $fetchMode);
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
		[$map, $fetchMode] = $this->terminalOptions($map, $fetchMode);
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
		[$map, $fetchMode] = $this->terminalOptions($map, $fetchMode);
		$this->executeFresh();

		try {
			if ($map === null) {
				/**
				 * @mago-expect lint:inline-variable-return Psalm makes this necessary
				 * @var list<array<array-key, mixed>> $records
				 */
				$records = $this->stmt->fetchAll($fetchMode);

				return $records;
			}

			/** @var list<T> $result */
			$result = [];
			/** @var list<array<array-key, mixed>> $records */
			$records = $this->stmt->fetchAll($fetchMode);

			foreach ($records as $record) {
				/** @var T $object */
				$object = $this->hydrator()->hydrate($record, $map, $this->sourcePath);
				$result[] = $object;
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
		[$map, $fetchMode] = $this->terminalOptions($map, $fetchMode);
		$this->executeFresh();

		try {
			while (($record = $this->fetchArrayRecord($fetchMode)) !== null) {
				if ($map === null) {
					yield $record;

					continue;
				}

				/** @var T $object */
				$object = $this->hydrator()->hydrate($record, $map, $this->sourcePath);

				yield $object;
			}
		} finally {
			$this->stmt->closeCursor();
		}
	}

	/**
	 * @return array{0: string|Closure|null, 1: int}
	 */
	private function terminalOptions(string|Closure|null $map, ?int $fetchMode): array
	{
		$mode = $fetchMode ?? ($map === null ? $this->db->getFetchMode() : PDO::FETCH_ASSOC);

		if ($map !== null && $mode !== PDO::FETCH_ASSOC) {
			throw new InvalidArgumentException('Hydration requires PDO::FETCH_ASSOC.');
		}

		return [$map, $mode];
	}

	private function executeFresh(): void
	{
		$this->db->connect();
		$this->stmt->closeCursor();
		$this->stmt->execute();
		$this->executed = false;
	}

	private function executeForFetch(): void
	{
		$this->db->connect();

		if (!$this->executed) {
			$this->stmt->closeCursor();
			$this->stmt->execute();
			$this->executed = true;
		}
	}

	/**
	 * @template T of object
	 *
	 * @param array<array-key, mixed> $record
	 * @param string|Closure(array<string, mixed>):class-string<T>|null $map
	 * @return ($map is null ? array<array-key, mixed> : T)
	 */
	private function hydrateRecord(array $record, string|Closure|null $map): array|object
	{
		if ($map === null) {
			return $record;
		}

		/**
		 * @mago-expect lint:inline-variable-return Psalm makes this necessary
		 * @var T $object
		 */
		$object = $this->hydrator()->hydrate($record, $map, $this->sourcePath);

		return $object;
	}

	private function hydrator(): Hydrator
	{
		return $this->hydrator ??= Hydrator::default();
	}

	public function run(): bool
	{
		$this->db->connect();
		$this->stmt->closeCursor();
		$this->executed = false;

		return $this->stmt->execute();
	}

	public function len(): int
	{
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
		return Debug::interpolate($this->query, $this->args);
	}

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

	protected function fetchArrayRecord(int $fetchMode): ?array
	{
		return $this->nullIfNot($this->stmt->fetch($fetchMode));
	}

	protected function nullIfNot(mixed $value): ?array
	{
		if (is_array($value)) {
			return $value;
		}

		return null;
	}
}
