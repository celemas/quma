<?php

declare(strict_types=1);

namespace Celema\Quma\Hydration;

use BackedEnum;
use Celema\Quma\Exception\InvalidTypeCoercion;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use ReflectionEnum;
use ReflectionNamedType;
use ValueError;

/** @internal */
final class TypeCoercer
{
	private const array DATE_FORMATS = [
		'Y-m-d H:i:s.uP',
		'Y-m-d H:i:sP',
		'Y-m-d\TH:i:s.uP',
		'Y-m-d\TH:i:sP',
		'Y-m-d H:i:s.u',
		'Y-m-d H:i:s',
		'Y-m-d',
	];

	public function coerce(mixed $value, TypeMetadata $type, HydrationContext $context): mixed
	{
		if ($value === null) {
			if ($type->allowsNull) {
				return null;
			}

			$this->fail($value, $context, $type->describe(), 'null is not allowed');
		}

		if ($type->kind === 'union') {
			return $this->coerceUnion($value, $type, $context);
		}

		return $this->coerceNamed($value, $type->names[0], $context, $type->describe());
	}

	private function coerceUnion(mixed $value, TypeMetadata $type, HydrationContext $context): mixed
	{
		foreach ($type->names as $name) {
			if ($this->satisfies($value, $name)) {
				return $value;
			}
		}

		$lastFailure = null;

		foreach (['enum', 'immutable', 'mutable', 'int', 'float', 'bool', 'string'] as $kind) {
			foreach ($type->names as $name) {
				if (!$this->matchesKind($name, $kind)) {
					continue;
				}

				try {
					return $this->coerceNamed($value, $name, $context, $type->describe());
				} catch (InvalidTypeCoercion $e) {
					$lastFailure = $e->getMessage();
				}
			}
		}

		$reason = 'no union arm accepted the value';

		if ($lastFailure !== null) {
			$reason .= '; last failure: ' . $lastFailure;
		}

		$this->fail($value, $context, $type->describe(), $reason);
	}

	private function coerceNamed(
		mixed $value,
		NamedTypeMetadata $name,
		HydrationContext $context,
		string $description,
	): mixed {
		return match ($name->scalar) {
			'int' => $this->coerceInt($value, $context, $description),
			'float' => $this->coerceFloat($value, $context, $description),
			'bool' => $this->coerceBool($value, $context, $description),
			'string' => $this->coerceString($value, $context, $description),
			default => $this->coerceSpecial($value, $name, $context, $description),
		};
	}

	private function coerceSpecial(
		mixed $value,
		NamedTypeMetadata $name,
		HydrationContext $context,
		string $description,
	): mixed {
		if ($name->date === 'immutable') {
			return $this->coerceImmutableDate($value, $context, $description);
		}

		if ($name->date === 'mutable') {
			return $this->coerceMutableDate($value, $context, $description);
		}

		if ($name->enum !== null) {
			return $this->coerceEnum($value, $name->enum, $context, $description);
		}

		$this->fail($value, $context, $description, 'unsupported declared type');
	}

	private function coerceInt(mixed $value, HydrationContext $context, string $description): int
	{
		if (is_int($value)) {
			return $value;
		}

		if (is_string($value)) {
			$int = $this->intFromString($value);

			if ($int !== null) {
				return $int;
			}
		}

		$this->fail($value, $context, $description, 'expected int or decimal integer string');
	}

	private function coerceFloat(mixed $value, HydrationContext $context, string $description): float
	{
		if (is_float($value)) {
			if (is_finite($value)) {
				return $value;
			}

			$this->fail($value, $context, $description, 'float must be finite');
		}

		if (is_int($value)) {
			return (float) $value;
		}

		if (is_string($value) && $value !== '' && trim($value) === $value && is_numeric($value)) {
			$float = (float) $value;

			if (is_finite($float)) {
				return $float;
			}
		}

		$this->fail($value, $context, $description, 'expected finite float, int, or numeric string');
	}

	private function coerceBool(mixed $value, HydrationContext $context, string $description): bool
	{
		if (is_bool($value)) {
			return $value;
		}

		if (is_int($value) && ($value === 0 || $value === 1)) {
			return $value === 1;
		}

		if (is_string($value)) {
			return match ($value) {
				'0', 'false', 'f' => false,
				'1', 'true', 't' => true,
				default => $this->fail(
					$value,
					$context,
					$description,
					'expected bool, 0/1, or lowercase true/false token',
				),
			};
		}

		$this->fail($value, $context, $description, 'expected bool, 0/1, or lowercase true/false token');
	}

	private function coerceString(mixed $value, HydrationContext $context, string $description): string
	{
		if (is_string($value)) {
			return $value;
		}

		if (is_int($value) || is_float($value)) {
			return (string) $value;
		}

		if (is_bool($value)) {
			return $value ? '1' : '0';
		}

		$this->fail($value, $context, $description, 'expected string or scalar value');
	}

	private function coerceImmutableDate(
		mixed $value,
		HydrationContext $context,
		string $description,
	): DateTimeImmutable {
		if ($value instanceof DateTimeImmutable) {
			return $value;
		}

		if (!is_string($value) || $value === '') {
			$this->fail($value, $context, $description, 'expected non-empty date string');
		}

		$timezone = new DateTimeZone(date_default_timezone_get());

		foreach (self::DATE_FORMATS as $format) {
			$date = DateTimeImmutable::createFromFormat('!' . $format, $value, $timezone);

			if ($date !== false && !$this->dateHasErrors() && $date->format($format) === $value) {
				return $date;
			}
		}

		$this->fail($value, $context, $description, 'unsupported date/time format');
	}

	private function coerceMutableDate(
		mixed $value,
		HydrationContext $context,
		string $description,
	): DateTime {
		if ($value instanceof DateTime) {
			return $value;
		}

		if (!is_string($value) || $value === '') {
			$this->fail($value, $context, $description, 'expected non-empty date string');
		}

		$timezone = new DateTimeZone(date_default_timezone_get());

		foreach (self::DATE_FORMATS as $format) {
			$date = DateTime::createFromFormat('!' . $format, $value, $timezone);

			if ($date !== false && !$this->dateHasErrors() && $date->format($format) === $value) {
				return $date;
			}
		}

		$this->fail($value, $context, $description, 'unsupported date/time format');
	}

	/** @param class-string<BackedEnum> $enum */
	private function coerceEnum(
		mixed $value,
		string $enum,
		HydrationContext $context,
		string $description,
	): BackedEnum {
		if ($value instanceof $enum) {
			return $value;
		}

		$backing = new ReflectionEnum($enum)->getBackingType();
		$backingType = $backing instanceof ReflectionNamedType ? $backing->getName() : null;

		if ($backingType === 'int') {
			if (is_int($value)) {
				$backingValue = $value;
			} elseif (is_string($value) && ($int = $this->intFromString($value)) !== null) {
				$backingValue = $int;
			} else {
				$this->fail($value, $context, $description, 'expected int enum backing value');
			}
		} elseif ($backingType === 'string') {
			if (!is_string($value)) {
				$this->fail($value, $context, $description, 'expected string enum backing value');
			}

			$backingValue = $value;
		} else {
			$this->fail($value, $context, $description, 'enum is not backed');
		}

		try {
			return $enum::from($backingValue);
		} catch (ValueError) {
			$this->fail($value, $context, $description, 'no enum case matches the backing value');
		}
	}

	private function satisfies(mixed $value, NamedTypeMetadata $name): bool
	{
		return match ($name->scalar) {
			'int' => is_int($value),
			'float' => is_float($value),
			'bool' => is_bool($value),
			'string' => is_string($value),
			default => $this->satisfiesSpecial($value, $name),
		};
	}

	private function satisfiesSpecial(mixed $value, NamedTypeMetadata $name): bool
	{
		if ($name->date === 'immutable') {
			return $value instanceof DateTimeImmutable;
		}

		if ($name->date === 'mutable') {
			return $value instanceof DateTime;
		}

		if ($name->enum !== null) {
			return $value instanceof $name->enum;
		}

		return false;
	}

	private function matchesKind(NamedTypeMetadata $name, string $kind): bool
	{
		return match ($kind) {
			'enum' => $name->enum !== null,
			'immutable', 'mutable' => $name->date === $kind,
			'int', 'float', 'bool', 'string' => $name->scalar === $kind,
			default => false,
		};
	}

	private function intFromString(string $value): ?int
	{
		if (!preg_match('/^-?\d+$/', $value)) {
			return null;
		}

		if (!$this->integerStringFits($value)) {
			return null;
		}

		return (int) $value;
	}

	private function integerStringFits(string $value): bool
	{
		$negative = str_starts_with($value, '-');
		$digits = $negative ? substr($value, 1) : $value;
		$digits = ltrim($digits, '0');

		if ($digits === '') {
			return true;
		}

		$limit = $negative ? substr((string) PHP_INT_MIN, 1) : (string) PHP_INT_MAX;

		return (
			strlen($digits) < strlen($limit)
			|| strlen($digits) === strlen($limit)
			&& strcmp($digits, $limit) <= 0
		);
	}

	private function dateHasErrors(): bool
	{
		$errors = DateTimeImmutable::getLastErrors();

		return (
			is_array($errors)
			&& (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)
		);
	}

	private function fail(
		mixed $value,
		HydrationContext $context,
		string $description,
		string $reason,
	): never {
		throw InvalidTypeCoercion::forContext($context, $value, $description, $reason);
	}
}
