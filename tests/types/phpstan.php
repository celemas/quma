<?php

declare(strict_types=1);

namespace Celema\Quma\Tests\Types;

use Celema\Quma\Database;
use stdClass;

use function PHPStan\Testing\assertType;

/**
 * Pins the types PHPStan sees through the public API with the bundled
 * extension. PHPStan checks this file in CI; nothing runs it.
 */
function phpstan_api(Database $db): void
{
	assertType('Celema\Quma\Folder', $db->members);
	assertType('Celema\Quma\Script', $db->members->byId);

	$query = $db->members->byId(['id' => 1]);
	assertType('Celema\Quma\Query', $query);
	assertType('Celema\Quma\Query', $db->members->list());
	assertType('Celema\Quma\Query', $db->members->byId(1, 'active'));
	assertType('Celema\Quma\Query', $db->members->byEmail(email: 'member@example.com'));

	assertType('array<mixed>', $query->one());
	assertType('stdClass', $query->one(stdClass::class));
	assertType('stdClass|null', $query->first(stdClass::class));
	assertType('list<stdClass>', $query->all(stdClass::class));
	assertType('Generator<int, stdClass, mixed, void>', $query->lazy(stdClass::class));
}
