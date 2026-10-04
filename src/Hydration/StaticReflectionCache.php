<?php

declare(strict_types=1);

namespace Celema\Quma\Hydration;

use BackedEnum;
use Celema\Quma\Column;
use Celema\Quma\Exception\InvalidHydrationTarget;
use Celema\Quma\Hydratable;
use DateTime;
use DateTimeImmutable;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use Throwable;

/** @internal */
final class StaticReflectionCache implements MetadataCache
{
	/** @var array<class-string, ClassMetadata> */
	private static array $entries = [];

	/** @param class-string $class */
	#[\Override]
	public function metadata(string $class): ClassMetadata
	{
		if (!array_key_exists($class, self::$entries)) {
			self::$entries[$class] = $this->build($class);
		}

		return self::$entries[$class];
	}

	/** @psalm-suppress PossiblyUnusedMethod */
	public static function reset(): void
	{
		self::$entries = [];
	}

	/** @param class-string $class */
	private function build(string $class): ClassMetadata
	{
		// Builtin type names like `int` are never class names.
		if (!class_exists($class)) {
			throw InvalidHydrationTarget::forTarget(
				$class,
				reason: 'target is not an existing class',
			);
		}

		if (is_a($class, Hydratable::class, true)) {
			return new ClassMetadata($class, true, true, null);
		}

		$reflection = new ReflectionClass($class);

		if (!$reflection->isInstantiable()) {
			throw InvalidHydrationTarget::forTarget($class, reason: 'target is not instantiable');
		}

		$constructor = $reflection->getConstructor();

		if ($constructor === null) {
			throw InvalidHydrationTarget::forTarget(
				$class,
				reason: 'target has no constructor; declare a public constructor or implement Hydratable',
			);
		}

		$parameters = [];

		// Reflection lists the parameters in declaration order.
		foreach ($constructor->getParameters() as $parameter) {
			$parameters[] = $this->parameterMetadata($class, $parameter);
		}

		return new ClassMetadata($class, false, true, $parameters);
	}

	/** @param class-string $class */
	private function parameterMetadata(
		string $class,
		ReflectionParameter $parameter,
	): ParameterMetadata {
		$name = $parameter->getName();

		if ($parameter->isVariadic()) {
			throw InvalidHydrationTarget::forParameter($class, $name, 'is variadic');
		}

		if ($parameter->isPassedByReference()) {
			throw InvalidHydrationTarget::forParameter($class, $name, 'is by-reference');
		}

		$type = $parameter->getType();

		if ($type === null) {
			throw InvalidHydrationTarget::forParameter($class, $name, 'has no declared type');
		}

		$column = $this->columnName($class, $parameter, $name);
		$hasDefault = $parameter->isDefaultValueAvailable();

		return new ParameterMetadata(
			$name,
			$column,
			$this->typeMetadata($class, $name, $type),
			$type->allowsNull(),
			$hasDefault,
			$hasDefault ? $parameter->getDefaultValue() : null,
		);
	}

	/**
	 * @param class-string $class
	 * @param non-empty-string $parameterName
	 * @return non-empty-string
	 */
	private function columnName(
		string $class,
		ReflectionParameter $parameter,
		string $parameterName,
	): string {
		$attributes = $parameter->getAttributes(Column::class);

		if ($attributes === []) {
			return $parameterName;
		}

		try {
			$column = $attributes[0]->newInstance();
		} catch (Throwable) {
			throw InvalidHydrationTarget::forParameter(
				$class,
				$parameterName,
				'has an invalid #[Column] attribute',
			);
		}

		$columnName = $column->name;

		if ($columnName === '' || trim($columnName) === '') {
			throw InvalidHydrationTarget::forParameter(
				$class,
				$parameterName,
				'has an empty #[Column] name',
			);
		}

		return $columnName;
	}

	/**
	 * @param class-string $class
	 * @param non-empty-string $parameterName
	 */
	private function typeMetadata(
		string $class,
		string $parameterName,
		ReflectionType $type,
	): TypeMetadata {
		if ($type instanceof ReflectionNamedType) {
			$name = $this->namedTypeMetadata($class, $parameterName, $type);

			return new TypeMetadata('named', $type->allowsNull(), [$name]);
		}

		if ($type instanceof ReflectionUnionType) {
			$names = [];

			foreach ($type->getTypes() as $inner) {
				if (!$inner instanceof ReflectionNamedType) {
					throw InvalidHydrationTarget::forParameter(
						$class,
						$parameterName,
						'uses an unsupported intersection or DNF type',
					);
				}

				if ($inner->getName() === 'null') {
					continue;
				}

				$names[] = $this->namedTypeMetadata($class, $parameterName, $inner);
			}

			/**
			 * ReflectionUnionType always contains at least one non-null arm in valid PHP;
			 * Psalm cannot infer that after the runtime null-arm filter above.
			 *
			 * @var non-empty-list<NamedTypeMetadata> $names
			 */
			return new TypeMetadata('union', $type->allowsNull(), $names);
		}

		throw InvalidHydrationTarget::forParameter(
			$class,
			$parameterName,
			'uses an unsupported intersection type',
		);
	}

	/**
	 * @param class-string $class
	 * @param non-empty-string $parameterName
	 */
	private function namedTypeMetadata(
		string $class,
		string $parameterName,
		ReflectionNamedType $type,
	): NamedTypeMetadata {
		// Reflection reports builtin type names in lowercase.
		$name = $type->getName();

		if ($type->isBuiltin() && in_array($name, ['int', 'float', 'bool', 'string'], true)) {
			return new NamedTypeMetadata($name, $name, null, null);
		}

		if ($name === DateTimeImmutable::class) {
			return new NamedTypeMetadata($name, null, 'immutable', null);
		}

		if ($name === DateTime::class) {
			return new NamedTypeMetadata($name, null, 'mutable', null);
		}

		if (is_subclass_of($name, BackedEnum::class)) {
			return new NamedTypeMetadata($name, null, null, $name);
		}

		throw InvalidHydrationTarget::forParameter(
			$class,
			$parameterName,
			"uses unsupported type {$name}",
		);
	}
}
