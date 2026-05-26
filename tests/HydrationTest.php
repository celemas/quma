<?php

declare(strict_types=1);

namespace Celemas\Quma\Tests;

use Celemas\Quma\Column;
use Celemas\Quma\Exception\HydrationFailure;
use Celemas\Quma\Exception\InvalidHydrationTarget;
use Celemas\Quma\Exception\InvalidTypeCoercion;
use Celemas\Quma\Exception\MissingColumn;
use Celemas\Quma\Hydratable;
use Celemas\Quma\Hydration\ClassMetadata;
use Celemas\Quma\Hydration\HydrationContext;
use Celemas\Quma\Hydration\Hydrator;
use Celemas\Quma\Hydration\MetadataCache;
use Celemas\Quma\Hydration\NamedTypeMetadata;
use Celemas\Quma\Hydration\StaticReflectionCache;
use Celemas\Quma\Hydration\TypeCoercer;
use Celemas\Quma\Hydration\TypeMetadata;
use Countable;
use DateTime;
use DateTimeImmutable;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;
use TypeError;

/**
 * @internal
 */
class HydrationTest extends TestCase
{
	protected function setUp(): void
	{
		StaticReflectionCache::reset();
		HydrationFactoryRow::$calls = 0;
		HydrationFactoryRow::$lastRow = [];
	}

	public function testHydratesConstructorDto(): void
	{
		$country = new Hydrator()->hydrate(
			[
				'id' => '49',
				'name' => 'Germany',
				'ignored' => true,
			],
			HydrationCountry::class,
			null,
		);

		$this->assertInstanceOf(HydrationCountry::class, $country);
		$this->assertSame(49, $country->id);
		$this->assertSame('Germany', $country->name);
		$this->assertSame(0, $country->population);
	}

	public function testMissingRequiredColumnIncludesContext(): void
	{
		$this->expectException(MissingColumn::class);
		$this->expectExceptionMessage(
			'Could not hydrate ' . HydrationCountry::class . ' from sql/country.sql',
		);
		$this->expectExceptionMessage("missing required column 'name' for parameter '\$name'");
		$this->expectExceptionMessage('Row keys: id');

		new Hydrator()->hydrate(['id' => 49], HydrationCountry::class, 'sql/country.sql');
	}

	public function testMissingOptionalColumnUsesDefaultButPresentNullDoesNot(): void
	{
		$hydrator = new Hydrator();

		$defaulted = $hydrator->hydrate(['id' => 1], HydrationOptionalValue::class, null);
		$this->assertInstanceOf(HydrationOptionalValue::class, $defaulted);
		$this->assertSame('anonymous', $defaulted->name);

		$nullable = $hydrator->hydrate(['id' => 1, 'name' => null], HydrationOptionalValue::class, null);
		$this->assertInstanceOf(HydrationOptionalValue::class, $nullable);
		$this->assertNull($nullable->name);
	}

	public function testPresentNullForNonNullableParameterThrows(): void
	{
		$this->expectException(InvalidTypeCoercion::class);
		$this->expectExceptionMessage('null is not allowed');

		new Hydrator()->hydrate(['value' => null], HydrationIntValue::class, null);
	}

	#[DataProvider('scalarCoercionProvider')]
	public function testCoercesScalarValues(string $class, mixed $input, mixed $expected): void
	{
		$object = new Hydrator()->hydrate(['value' => $input], $class, null);

		$this->assertSame($expected, $object->value);
	}

	public static function scalarCoercionProvider(): array
	{
		return [
			'int from int' => [HydrationIntValue::class, 42, 42],
			'int from string' => [HydrationIntValue::class, '-7', -7],
			'int from zero string' => [HydrationIntValue::class, '000', 0],
			'float from float' => [HydrationFloatValue::class, 1.5, 1.5],
			'float from int' => [HydrationFloatValue::class, 1, 1.0],
			'float from exponent string' => [HydrationFloatValue::class, '1e3', 1000.0],
			'bool from bool' => [HydrationBoolValue::class, true, true],
			'bool from int' => [HydrationBoolValue::class, 0, false],
			'bool from false string' => [HydrationBoolValue::class, 'false', false],
			'bool from f string' => [HydrationBoolValue::class, 'f', false],
			'bool from true string' => [HydrationBoolValue::class, 'true', true],
			'bool from t string' => [HydrationBoolValue::class, 't', true],
			'string from string' => [HydrationStringValue::class, 'Chuck', 'Chuck'],
			'string from int' => [HydrationStringValue::class, 7, '7'],
			'string from bool' => [HydrationStringValue::class, false, '0'],
		];
	}

	#[DataProvider('scalarFailureProvider')]
	public function testRejectsInvalidScalarValues(string $class, mixed $input): void
	{
		$this->expectException(InvalidTypeCoercion::class);

		new Hydrator()->hydrate(['value' => $input], $class, null);
	}

	public static function scalarFailureProvider(): array
	{
		return [
			'int rejects empty string' => [HydrationIntValue::class, ''],
			'int rejects decimal string' => [HydrationIntValue::class, '1.2'],
			'int rejects exponent string' => [HydrationIntValue::class, '1e3'],
			'int rejects overflow' => [HydrationIntValue::class, '999999999999999999999999999999'],
			'int rejects bool' => [HydrationIntValue::class, true],
			'float rejects empty string' => [HydrationFloatValue::class, ''],
			'float rejects text' => [HydrationFloatValue::class, 'nope'],
			'float rejects infinity' => [HydrationFloatValue::class, INF],
			'bool rejects empty string' => [HydrationBoolValue::class, ''],
			'bool rejects yes' => [HydrationBoolValue::class, 'yes'],
			'bool rejects two' => [HydrationBoolValue::class, '2'],
			'bool rejects array' => [HydrationBoolValue::class, []],
			'string rejects array' => [HydrationStringValue::class, []],
			'string rejects object' => [HydrationStringValue::class, new RuntimeException('nope')],
		];
	}

	public function testHydratesBackedEnums(): void
	{
		$hydrator = new Hydrator();

		$string = $hydrator->hydrate(['value' => 'active'], HydrationStringEnumValue::class, null);
		$existing = $hydrator->hydrate(
			['value' => HydrationStatus::Active],
			HydrationStringEnumValue::class,
			null,
		);
		$int = $hydrator->hydrate(['value' => 1], HydrationIntEnumValue::class, null);
		$intString = $hydrator->hydrate(['value' => '1'], HydrationIntEnumValue::class, null);

		$this->assertSame(HydrationStatus::Active, $string->value);
		$this->assertSame(HydrationStatus::Active, $existing->value);
		$this->assertSame(HydrationRank::First, $int->value);
		$this->assertSame(HydrationRank::First, $intString->value);
	}

	public function testRejectsInvalidEnumValues(): void
	{
		$this->expectException(InvalidTypeCoercion::class);
		$this->expectExceptionMessage('no enum case matches');

		new Hydrator()->hydrate(['value' => 'missing'], HydrationStringEnumValue::class, null);
	}

	#[DataProvider('enumKindFailureProvider')]
	public function testRejectsWrongEnumBackingValueKinds(
		string $class,
		mixed $value,
		string $message,
	): void {
		$this->expectException(InvalidTypeCoercion::class);
		$this->expectExceptionMessage($message);

		new Hydrator()->hydrate(['value' => $value], $class, null);
	}

	public static function enumKindFailureProvider(): array
	{
		return [
			'int enum rejects decimal string' => [
				HydrationIntEnumValue::class,
				'1.2',
				'expected int enum backing value',
			],
			'string enum rejects int' => [
				HydrationStringEnumValue::class,
				1,
				'expected string enum backing value',
			],
		];
	}

	#[DataProvider('dateProvider')]
	public function testHydratesDateFormats(string $value): void
	{
		$object = new Hydrator()->hydrate(['value' => $value], HydrationImmutableDateValue::class, null);

		$this->assertInstanceOf(HydrationImmutableDateValue::class, $object);
		$this->assertSame($value, $object->value->format($this->formatForDateValue($value)));
	}

	public static function dateProvider(): array
	{
		return [
			['2024-01-02 03:04:05.123456+01:00'],
			['2024-01-02 03:04:05+01:00'],
			['2024-01-02T03:04:05.123456+01:00'],
			['2024-01-02T03:04:05+01:00'],
			['2024-01-02 03:04:05.123456'],
			['2024-01-02 03:04:05'],
			['2024-01-02'],
		];
	}

	public function testHydratesExistingImmutableDate(): void
	{
		$date = new DateTimeImmutable('2024-01-02 03:04:05');
		$object = new Hydrator()->hydrate(['value' => $date], HydrationImmutableDateValue::class, null);

		$this->assertSame($date, $object->value);
	}

	public function testHydratesExistingMutableDate(): void
	{
		$date = new DateTime('2024-01-02 03:04:05');
		$object = new Hydrator()->hydrate(['value' => $date], HydrationMutableDateValue::class, null);

		$this->assertSame($date, $object->value);
	}

	public function testHydratesMutableDateString(): void
	{
		$object = new Hydrator()->hydrate(
			['value' => '2024-01-02 03:04:05'],
			HydrationMutableDateValue::class,
			null,
		);

		$this->assertSame('2024-01-02 03:04:05', $object->value->format('Y-m-d H:i:s'));
	}

	#[DataProvider('dateFailureProvider')]
	public function testRejectsInvalidDateStrings(string $class, mixed $value): void
	{
		$this->expectException(InvalidTypeCoercion::class);

		new Hydrator()->hydrate(['value' => $value], $class, null);
	}

	public static function dateFailureProvider(): array
	{
		return [
			'immutable invalid date' => [HydrationImmutableDateValue::class, '2024-02-31'],
			'immutable empty string' => [HydrationImmutableDateValue::class, ''],
			'mutable invalid date' => [HydrationMutableDateValue::class, '2024-02-31'],
			'mutable empty string' => [HydrationMutableDateValue::class, ''],
		];
	}

	public function testHydratesUnionTypesDeterministically(): void
	{
		$hydrator = new Hydrator();

		$nullable = $hydrator->hydrate(['value' => null], HydrationNullableIntValue::class, null);
		$explicitNullable = $hydrator->hydrate(
			['value' => null],
			HydrationExplicitNullableIntValue::class,
			null,
		);
		$multiNullable = $hydrator->hydrate(['value' => null], HydrationMultiNullableValue::class, null);
		$intFloat = $hydrator->hydrate(['value' => '1.5'], HydrationIntFloatValue::class, null);
		$exactFloat = $hydrator->hydrate(['value' => 1.5], HydrationIntFloatValue::class, null);
		$boolInt = $hydrator->hydrate(['value' => true], HydrationBoolIntValue::class, null);
		$stringInt = $hydrator->hydrate(['value' => '42'], HydrationStringIntValue::class, null);

		$this->assertNull($nullable->value);
		$this->assertNull($explicitNullable->value);
		$this->assertNull($multiNullable->value);
		$this->assertSame(1.5, $intFloat->value);
		$this->assertSame(1.5, $exactFloat->value);
		$this->assertTrue($boolInt->value);
		$this->assertSame('42', $stringInt->value);
	}

	public function testHydratesUnionTypesWithExactObjectMatches(): void
	{
		$hydrator = new Hydrator();
		$immutable = new DateTimeImmutable('2024-01-02 03:04:05');
		$mutable = new DateTime('2024-01-02 03:04:05');

		$immutableObject = $hydrator->hydrate(
			['value' => $immutable],
			HydrationImmutableDateOrStringValue::class,
			null,
		);
		$mutableObject = $hydrator->hydrate(
			['value' => $mutable],
			HydrationMutableDateOrStringValue::class,
			null,
		);
		$enumObject = $hydrator->hydrate(
			['value' => HydrationStatus::Active],
			HydrationStatusOrStringValue::class,
			null,
		);

		$this->assertSame($immutable, $immutableObject->value);
		$this->assertSame($mutable, $mutableObject->value);
		$this->assertSame(HydrationStatus::Active, $enumObject->value);
	}

	public function testRejectsUnionValuesThatMatchNoArm(): void
	{
		$this->expectException(InvalidTypeCoercion::class);
		$this->expectExceptionMessage('no union arm accepted');

		new Hydrator()->hydrate(['value' => []], HydrationIntFloatValue::class, null);
	}

	public function testTypeCoercerAllowsNullWhenMetadataAllowsNull(): void
	{
		$type = new TypeMetadata(
			'named',
			true,
			[new NamedTypeMetadata('int', true, null, 'int', null, null)],
		);

		$this->assertNull(new TypeCoercer()->coerce(null, $type, $this->coercionContext()));
	}

	public function testTypeCoercerRejectsUnsupportedNamedMetadata(): void
	{
		$type = new TypeMetadata(
			'named',
			false,
			[new NamedTypeMetadata('unknown', false, null, null, null, null)],
		);

		$this->expectException(InvalidTypeCoercion::class);
		$this->expectExceptionMessage('unsupported declared type');

		new TypeCoercer()->coerce('x', $type, $this->coercionContext());
	}

	public function testTypeCoercerSkipsUnsupportedExactUnionMetadata(): void
	{
		$type = new TypeMetadata(
			'union',
			false,
			[
				new NamedTypeMetadata('unknown', false, null, null, null, null),
				new NamedTypeMetadata('int', true, null, 'int', null, null),
			],
		);

		$this->assertSame(1, new TypeCoercer()->coerce(1, $type, $this->coercionContext()));
	}

	public function testTypeCoercerRejectsUnbackedEnumMetadata(): void
	{
		$type = new TypeMetadata(
			'named',
			false,
			[new NamedTypeMetadata(
				HydrationUnit::class,
				false,
				HydrationUnit::class,
				null,
				null,
				HydrationUnit::class,
			)],
		);

		$this->expectException(InvalidTypeCoercion::class);
		$this->expectExceptionMessage('enum is not backed');

		new TypeCoercer()->coerce('One', $type, $this->coercionContext());
	}

	public function testInvalidTypeCoercionFormatsResourceValues(): void
	{
		$resource = fopen('php://memory', 'r');
		assert(is_resource($resource), 'Test resource must be available.');

		try {
			$this->expectException(InvalidTypeCoercion::class);
			$this->expectExceptionMessage('stream resource');

			new Hydrator()->hydrate(['value' => $resource], HydrationStringValue::class, null);
		} finally {
			fclose($resource);
		}
	}

	public function testColumnAttributeRemapsInputColumn(): void
	{
		$user = new Hydrator()->hydrate(
			[
				'id' => 1,
				'email_address' => 'a@example.com',
			],
			HydrationColumnUser::class,
			null,
		);

		$this->assertInstanceOf(HydrationColumnUser::class, $user);
		$this->assertSame('a@example.com', $user->email);
	}

	public function testMissingAttributeColumnReportsColumnAndParameter(): void
	{
		$this->expectException(MissingColumn::class);
		$this->expectExceptionMessage("column 'email_address'");
		$this->expectExceptionMessage("parameter '\$email'");

		new Hydrator()->hydrate(['id' => 1], HydrationColumnUser::class, null);
	}

	public function testHydratableFactoryReceivesStringKeyRowAndSkipsConstructorMetadata(): void
	{
		$row = [0 => 'ignored', 'name' => 'Chuck'];
		$object = new Hydrator()->hydrate($row, HydrationFactoryRow::class, null);

		$this->assertInstanceOf(HydrationFactoryRow::class, $object);
		$this->assertSame('Chuck!', $object->name);
		$this->assertSame(1, HydrationFactoryRow::$calls);
		$this->assertSame(['name' => 'Chuck'], HydrationFactoryRow::$lastRow);
	}

	public function testHydratableExceptionsBubble(): void
	{
		$this->expectException(HydrationFailure::class);
		$this->expectExceptionMessage('factory validation failed');

		new Hydrator()->hydrate([], HydrationFactoryThrowsHydrationFailure::class, null);
	}

	public function testHydratableThrowablesAreWrapped(): void
	{
		try {
			new Hydrator()->hydrate(['id' => 1], HydrationFactoryThrowsRuntime::class, 'sql/factory.sql');
			$this->fail('Expected a hydration exception.');
		} catch (HydrationFailure $e) {
			$this->assertStringContainsString('from sql/factory.sql', $e->getMessage());
			$this->assertStringContainsString('Hydratable::fromRow() failed', $e->getMessage());
			$this->assertInstanceOf(RuntimeException::class, $e->getPrevious());
		}
	}

	public function testClosureResolverCanMapDifferentConcreteClasses(): void
	{
		$hydrator = new Hydrator();
		$resolver = static fn(array $row): string => $row['type'] === 'created'
			? HydrationCreatedEvent::class
			: HydrationDeletedEvent::class;

		$created = $hydrator->hydrate(['type' => 'created', 'id' => 1], $resolver, null);
		$deleted = $hydrator->hydrate(['type' => 'deleted', 'id' => 2], $resolver, null);

		$this->assertInstanceOf(HydrationCreatedEvent::class, $created);
		$this->assertInstanceOf(HydrationDeletedEvent::class, $deleted);
	}

	public function testHydratesClassWithoutConstructor(): void
	{
		$object = new Hydrator()->hydrate(['ignored' => true], HydrationNoConstructor::class, null);

		$this->assertInstanceOf(HydrationNoConstructor::class, $object);
	}

	public function testRejectsBuiltinTargetInReflectionCache(): void
	{
		$this->expectException(InvalidHydrationTarget::class);
		$this->expectExceptionMessage('target is not an existing class');

		new StaticReflectionCache()->metadata('int');
	}

	public function testAbstractHydratableFactoryFailureIsWrapped(): void
	{
		try {
			new Hydrator()->hydrate([], HydrationAbstractFactory::class, null);
			$this->fail('Expected hydration exception.');
		} catch (HydrationFailure $e) {
			$this->assertStringContainsString('Hydratable::fromRow() failed', $e->getMessage());
			$this->assertInstanceOf(\Error::class, $e->getPrevious());
		}
	}

	public function testMetadataRejectsPrivateConstructors(): void
	{
		$this->expectException(InvalidHydrationTarget::class);
		$this->expectExceptionMessage('target is not instantiable');

		new Hydrator()->hydrate([], HydrationPrivateConstructor::class, null);
	}

	public function testInvalidColumnAttributeArgumentsThrow(): void
	{
		$this->expectException(InvalidHydrationTarget::class);
		$this->expectExceptionMessage('has an invalid #[Column] attribute');

		new Hydrator()->hydrate(['value' => 'x'], HydrationInvalidColumn::class, null);
	}

	public function testInconsistentHydratableMetadataIsRejected(): void
	{
		$cache = new class implements MetadataCache {
			#[\Override]
			public function metadata(string $class): ClassMetadata
			{
				return new ClassMetadata(stdClass::class, true, true, null);
			}
		};

		$this->expectException(InvalidHydrationTarget::class);
		$this->expectExceptionMessage('target is not hydratable');

		new Hydrator($cache)->hydrate([], stdClass::class, null);
	}

	public function testInconsistentInstantiabilityMetadataIsRejected(): void
	{
		$cache = new class implements MetadataCache {
			#[\Override]
			public function metadata(string $class): ClassMetadata
			{
				return new ClassMetadata(stdClass::class, false, false, []);
			}
		};

		$this->expectException(InvalidHydrationTarget::class);
		$this->expectExceptionMessage('target is not instantiable');

		new Hydrator($cache)->hydrate([], stdClass::class, null);
	}

	public function testConstructorTypeErrorsAreWrapped(): void
	{
		try {
			new Hydrator()->hydrate(['value' => 1], HydrationConstructorThrowsTypeError::class, null);
			$this->fail('Expected a type coercion exception.');
		} catch (InvalidTypeCoercion $e) {
			$this->assertStringContainsString('constructor rejected hydrated arguments', $e->getMessage());
			$this->assertInstanceOf(TypeError::class, $e->getPrevious());
		}
	}

	#[DataProvider('invalidTargetProvider')]
	public function testInvalidTargetsThrow(string $class): void
	{
		$this->expectException(InvalidHydrationTarget::class);

		new Hydrator()->hydrate(['value' => 1], $class, null);
	}

	public static function invalidTargetProvider(): array
	{
		return [
			'scalar target' => ['int'],
			'unknown target' => ['Celemas\\Quma\\Tests\\NoSuchHydrationClass'],
			'abstract target' => [HydrationAbstractTarget::class],
			'untyped parameter' => [HydrationUntypedParameter::class],
			'array parameter' => [HydrationArrayParameter::class],
			'unit enum parameter' => [HydrationUnitEnumValue::class],
			'intersection parameter' => [HydrationIntersectionParameter::class],
			'dnf parameter' => [HydrationDnfParameter::class],
			'empty column' => [HydrationEmptyColumn::class],
			'variadic parameter' => [HydrationVariadicParameter::class],
			'by-reference parameter' => [HydrationByReferenceParameter::class],
			'unsupported object parameter' => [HydrationUnsupportedObjectParameter::class],
		];
	}

	public function testClosureReturningNullThrows(): void
	{
		$this->expectException(InvalidHydrationTarget::class);
		$this->expectExceptionMessage('expected class-string, got null');

		new Hydrator()->hydrate(['id' => 1], static fn(array $row): null => null, null);
	}

	public function testStaticReflectionCacheReusesMetadataInstance(): void
	{
		$cache = new StaticReflectionCache();
		$first = $cache->metadata(HydrationCountry::class);
		$second = $cache->metadata(HydrationCountry::class);

		$this->assertSame($first, $second);
		$this->assertCount(3, $first->parameters);
		$this->assertSame('population', $first->parameters[2]->name);
		$this->assertTrue($first->parameters[2]->hasDefault);
	}

	private function coercionContext(): HydrationContext
	{
		return new HydrationContext(
			HydrationStringValue::class,
			'value',
			'value',
			null,
			['value'],
		);
	}

	private function formatForDateValue(string $value): string
	{
		if (str_contains($value, 'T') && str_contains($value, '.')) {
			return 'Y-m-d\TH:i:s.uP';
		}

		if (str_contains($value, 'T')) {
			return 'Y-m-d\TH:i:sP';
		}

		if (str_contains($value, '.')) {
			return str_contains($value, '+') ? 'Y-m-d H:i:s.uP' : 'Y-m-d H:i:s.u';
		}

		if (str_contains($value, '+')) {
			return 'Y-m-d H:i:sP';
		}

		return strlen($value) === 10 ? 'Y-m-d' : 'Y-m-d H:i:s';
	}
}

final class HydrationNoConstructor {}

final readonly class HydrationCountry
{
	public function __construct(
		public int $id,
		public string $name,
		public int $population = 0,
	) {}
}

final readonly class HydrationOptionalValue
{
	public function __construct(
		public int $id,
		public ?string $name = 'anonymous',
	) {}
}

final readonly class HydrationIntValue
{
	public function __construct(
		public int $value,
	) {}
}

final readonly class HydrationFloatValue
{
	public function __construct(
		public float $value,
	) {}
}

final readonly class HydrationBoolValue
{
	public function __construct(
		public bool $value,
	) {}
}

final readonly class HydrationStringValue
{
	public function __construct(
		public string $value,
	) {}
}

enum HydrationStatus: string
{
	case Active = 'active';
}

enum HydrationRank: int
{
	case First = 1;
}

enum HydrationUnit
{
	case One;
}

final readonly class HydrationStringEnumValue
{
	public function __construct(
		public HydrationStatus $value,
	) {}
}

final readonly class HydrationIntEnumValue
{
	public function __construct(
		public HydrationRank $value,
	) {}
}

final readonly class HydrationUnitEnumValue
{
	public function __construct(
		public HydrationUnit $value,
	) {}
}

final readonly class HydrationImmutableDateValue
{
	public function __construct(
		public DateTimeImmutable $value,
	) {}
}

final readonly class HydrationMutableDateValue
{
	public function __construct(
		public DateTime $value,
	) {}
}

final readonly class HydrationNullableIntValue
{
	public function __construct(
		public ?int $value,
	) {}
}

final readonly class HydrationExplicitNullableIntValue
{
	public function __construct(
		public ?int $value,
	) {}
}

final readonly class HydrationIntFloatValue
{
	public function __construct(
		public int|float $value,
	) {}
}

final readonly class HydrationMultiNullableValue
{
	public function __construct(
		public int|string|null $value,
	) {}
}

final readonly class HydrationBoolIntValue
{
	public function __construct(
		public bool|int $value,
	) {}
}

final readonly class HydrationStringIntValue
{
	public function __construct(
		public string|int $value,
	) {}
}

final readonly class HydrationImmutableDateOrStringValue
{
	public function __construct(
		public DateTimeImmutable|string $value,
	) {}
}

final readonly class HydrationMutableDateOrStringValue
{
	public function __construct(
		public DateTime|string $value,
	) {}
}

final readonly class HydrationStatusOrStringValue
{
	public function __construct(
		public HydrationStatus|string $value,
	) {}
}

final readonly class HydrationColumnUser
{
	public function __construct(
		public int $id,
		#[Column('email_address')]
		public string $email,
	) {}
}

final class HydrationFactoryRow implements Hydratable
{
	public static int $calls = 0;

	/** @var array<string, mixed> */
	public static array $lastRow = [];

	private function __construct(
		public string $name,
	) {}

	public static function fromRow(array $row): static
	{
		self::$calls++;
		self::$lastRow = $row;

		return new self($row['name'] . '!');
	}
}

final class HydrationFactoryThrowsHydrationFailure implements Hydratable
{
	public static function fromRow(array $row): static
	{
		throw new HydrationFailure('factory validation failed');
	}
}

final class HydrationFactoryThrowsRuntime implements Hydratable
{
	public static function fromRow(array $row): static
	{
		throw new RuntimeException('boom');
	}
}

abstract class HydrationAbstractFactory implements Hydratable {}

abstract class HydrationEvent
{
	public function __construct(
		public int $id,
	) {}
}

final class HydrationCreatedEvent extends HydrationEvent {}

final class HydrationDeletedEvent extends HydrationEvent {}

abstract class HydrationAbstractTarget {}

final class HydrationPrivateConstructor
{
	private function __construct() {}
}

final class HydrationConstructorThrowsTypeError
{
	public function __construct(int $value)
	{
		throw new TypeError('constructor failed');
	}
}

final class HydrationUntypedParameter
{
	public function __construct($value) {}
}

final class HydrationArrayParameter
{
	public function __construct(array $value) {}
}

final class HydrationUnsupportedObjectParameter
{
	public function __construct(RuntimeException $value) {}
}

final class HydrationIntersectionParameter
{
	public function __construct(Iterator&Countable $value) {}
}

final class HydrationDnfParameter
{
	public function __construct((Iterator&Countable)|int $value) {}
}

final class HydrationEmptyColumn
{
	public function __construct(#[Column('')] string $value) {}
}

final class HydrationInvalidColumn
{
	public function __construct(#[Column([])] string $value) {}
}

final class HydrationVariadicParameter
{
	public function __construct(string ...$value) {}
}

final class HydrationByReferenceParameter
{
	public function __construct(string &$value) {}
}
