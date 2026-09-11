<?php

declare(strict_types=1);

namespace Celema\Quma\Tests;

use Celema\Quma\Database;
use Generator;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

class QueryFetchModeTest extends TestCase
{
	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
		self::createTestDb();
	}

	#[DataProvider('arrayFetchModes')]
	public function testArrayFetchModesReturnRowsConsistently(int $mode, array $row): void
	{
		$db = new Database($this->connection()->fetch($mode));
		$query = $db->members->byId(1);

		$this->assertSame($row, $query->one());
		$this->assertSame($row, $query->first());
		$this->assertSame($row, $query->fetch());
		$this->assertNull($query->fetch());
		$this->assertSame([$row], $query->all());
		$this->assertSame([$row], iterator_to_array($query->lazy()));
	}

	public static function arrayFetchModes(): array
	{
		$row = ['member' => 1, 'name' => 'Chuck Schuldiner', 'left' => null];

		return [
			'associative' => [PDO::FETCH_ASSOC, $row],
			'numeric' => [PDO::FETCH_NUM, array_values($row)],
			'both' => [
				PDO::FETCH_BOTH,
				[
					'member' => 1,
					0 => 1,
					'name' => 'Chuck Schuldiner',
					1 => 'Chuck Schuldiner',
					'left' => null,
					2 => null,
				],
			],
			'named' => [PDO::FETCH_NAMED, $row],
		];
	}

	#[DataProvider('unsupportedFetchModes')]
	public function testUnsupportedFetchModesThrowInsteadOfReturningMissingRows(
		string $method,
		int $mode,
		bool $connectionDefault,
	): void {
		$conn = $this->connection();

		if ($connectionDefault) {
			$conn->fetch($mode);
		}

		$query = new Database($conn)->members->byId(1);
		$this->expectException(InvalidArgumentException::class);
		$result = $query->$method(fetchMode: $connectionDefault ? null : $mode);

		if ($result instanceof Generator) {
			iterator_to_array($result);
		}
	}

	public static function unsupportedFetchModes(): iterable
	{
		$modes = [
			'object' => PDO::FETCH_OBJ,
			'column' => PDO::FETCH_COLUMN,
			'class' => PDO::FETCH_CLASS,
			'lazy object' => PDO::FETCH_LAZY,
			'grouped' => PDO::FETCH_ASSOC | PDO::FETCH_GROUP,
			'key pairs' => PDO::FETCH_KEY_PAIR,
			'PDO default' => PDO::FETCH_DEFAULT,
			'invalid' => -1,
		];

		foreach ($modes as $name => $mode) {
			foreach (['one', 'first', 'fetch', 'all', 'lazy'] as $method) {
				yield "{$method}, {$name}, explicit" => [$method, $mode, false];
				yield "{$method}, {$name}, connection default" => [$method, $mode, true];
			}
		}
	}

	public function testExplicitFetchModeOverridesUnsupportedConnectionDefault(): void
	{
		$db = new Database($this->connection()->fetch(PDO::FETCH_OBJ));

		$row = $db->members->byId(1)->one(fetchMode: PDO::FETCH_NUM);

		$this->assertSame([1, 'Chuck Schuldiner', null], $row);
	}

	public function testUnsupportedFetchModeDoesNotExecuteQuery(): void
	{
		$db = $this->getDb();
		$db->begin();

		try {
			$db->members->delete(['name' => 'Chuck Schuldiner'])->all(fetchMode: PDO::FETCH_OBJ);
			$this->fail('An unsupported fetch mode must throw before executing the query.');
		} catch (InvalidArgumentException) {
			$this->assertSame('Chuck Schuldiner', $db->members->byId(1)->one()['name']);
		} finally {
			$db->rollback();
		}
	}
}
