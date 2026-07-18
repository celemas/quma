<?php

declare(strict_types=1);

namespace Celema\Quma\Hydration;

use Celema\Quma\Exception\HydrationFailure;
use Celema\Quma\Exception\InvalidHydrationTarget;
use Celema\Quma\Exception\InvalidTypeCoercion;
use Celema\Quma\Exception\MissingColumn;
use Celema\Quma\Hydratable;
use Closure;
use Throwable;
use TypeError;

/** @internal */
final class Hydrator
{
	private MetadataCache $cache;
	private TypeCoercer $coercer;

	public function __construct(?MetadataCache $cache = null, ?TypeCoercer $coercer = null)
	{
		$this->cache = $cache ?? new StaticReflectionCache();
		$this->coercer = $coercer ?? new TypeCoercer();
	}

	public static function default(): self
	{
		return new self();
	}

	/**
	 * @param array<array-key, mixed> $record
	 * @param string|Closure $map
	 */
	public function hydrate(array $record, string|Closure $map, ?string $sourcePath): object
	{
		$row = $this->stringKeyRow($record);
		$rowKeys = array_keys($row);
		$class = $this->resolveClass($map, $row, $sourcePath, $rowKeys);

		try {
			$metadata = $this->cache->metadata($class);
		} catch (InvalidHydrationTarget $e) {
			throw InvalidHydrationTarget::forTarget(
				$class,
				$sourcePath,
				$rowKeys,
				$e->getMessage(),
				$e,
			);
		}

		if ($metadata->hydratable) {
			return $this->hydrateViaFactory($class, $row, $sourcePath, $rowKeys);
		}

		return $this->hydrateViaConstructor($metadata, $row, $sourcePath, $rowKeys);
	}

	/**
	 * @param array<array-key, mixed> $record
	 * @return array<string, mixed>
	 * @psalm-suppress MixedAssignment
	 */
	private function stringKeyRow(array $record): array
	{
		$row = [];

		foreach ($record as $key => $value) {
			if (!is_string($key)) {
				continue;
			}

			$row[$key] = $value;
		}

		return $row;
	}

	/**
	 * @param array<string, mixed> $row
	 * @param list<string> $rowKeys
	 * @param string|Closure $map
	 * @return class-string
	 */
	private function resolveClass(
		string|Closure $map,
		array $row,
		?string $sourcePath,
		array $rowKeys,
	): string {
		if (is_string($map)) {
			return $this->validateClass($map, $sourcePath, $rowKeys);
		}

		$result = $map($row);

		if (!is_string($result)) {
			throw InvalidHydrationTarget::forClosureResult($result, $sourcePath, $rowKeys);
		}

		return $this->validateClass($result, $sourcePath, $rowKeys);
	}

	/**
	 * @param list<string> $rowKeys
	 * @return class-string
	 */
	private function validateClass(string $target, ?string $sourcePath, array $rowKeys): string
	{
		if ($this->isBuiltinTypeName($target)) {
			throw InvalidHydrationTarget::forTarget(
				$target,
				$sourcePath,
				$rowKeys,
				'built-in type names cannot be hydrated',
			);
		}

		if (!class_exists($target)) {
			throw InvalidHydrationTarget::forTarget(
				$target,
				$sourcePath,
				$rowKeys,
				'target is not an existing class',
			);
		}

		return $target;
	}

	/**
	 * @param array<string, mixed> $row
	 * @param list<string> $rowKeys
	 * @param class-string $class
	 */
	private function hydrateViaFactory(
		string $class,
		array $row,
		?string $sourcePath,
		array $rowKeys,
	): object {
		if (!is_a($class, Hydratable::class, true)) {
			throw InvalidHydrationTarget::forTarget(
				$class,
				$sourcePath,
				$rowKeys,
				'target is not hydratable',
			);
		}

		$hydratable = $class;

		try {
			return $hydratable::fromRow($row);
		} catch (HydrationFailure $e) {
			throw $e;
		} catch (Throwable $e) {
			throw HydrationFailure::fromHydratableFailure($class, $sourcePath, $rowKeys, $e);
		}
	}

	/**
	 * @param array<string, mixed> $row
	 * @param list<string> $rowKeys
	 */
	private function hydrateViaConstructor(
		ClassMetadata $metadata,
		array $row,
		?string $sourcePath,
		array $rowKeys,
	): object {
		if (!$metadata->instantiable) {
			throw InvalidHydrationTarget::forTarget(
				$metadata->class,
				$sourcePath,
				$rowKeys,
				'target is not instantiable',
			);
		}

		/** @var array<string, mixed> $args */
		$args = [];

		foreach ($metadata->parameters ?? [] as $parameter) {
			if (array_key_exists($parameter->column, $row)) {
				if ($parameter->nullable && $row[$parameter->column] === null) {
					$args[$parameter->name] = null;

					continue;
				}

				/** @psalm-suppress MixedAssignment */
				$args[$parameter->name] = $this->coercer->coerce(
					$row[$parameter->column],
					$parameter->type,
					new HydrationContext(
						$metadata->class,
						$parameter->name,
						$parameter->column,
						$sourcePath,
						$rowKeys,
					),
				);

				continue;
			}

			if ($parameter->hasDefault) {
				/** @psalm-suppress MixedAssignment */
				$args[$parameter->name] = $parameter->defaultValue;

				continue;
			}

			throw MissingColumn::forColumn(
				$metadata->class,
				$parameter->name,
				$parameter->column,
				$sourcePath,
				$rowKeys,
			);
		}

		try {
			$class = $metadata->class;

			/** @psalm-suppress MixedMethodCall */
			return new $class(...$args);
		} catch (TypeError $e) {
			throw InvalidTypeCoercion::constructorFailure(
				$metadata->class,
				$sourcePath,
				$rowKeys,
				$e,
			);
		}
	}

	private function isBuiltinTypeName(string $target): bool
	{
		return in_array(
			strtolower($target),
			[
				'array',
				'bool',
				'callable',
				'false',
				'float',
				'int',
				'iterable',
				'mixed',
				'never',
				'null',
				'object',
				'string',
				'true',
				'void',
			],
			true,
		);
	}
}
