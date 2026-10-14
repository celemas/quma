<?php

declare(strict_types=1);

namespace Celema\Quma\Tests\Types;

use Celema\Quma\Database;
use Generator;
use stdClass;

/**
 * Pins the types analyzers see through the public API. Psalm checks this
 * file in CI; nothing runs it.
 *
 * @return list<mixed>
 */
function api(Database $db): array
{
	$folder = $db->members;
	/** @psalm-check-type-exact $folder = \Celema\Quma\Folder */

	$script = $db->members->byId;
	/** @psalm-check-type-exact $script = \Celema\Quma\Script */

	$query = $db->members->byId(['id' => 1]);
	/** @psalm-check-type-exact $query = \Celema\Quma\Query */

	$row = $query->one();
	/** @psalm-check-type-exact $row = array<array-key, mixed> */

	$object = $query->one(stdClass::class);
	/** @psalm-check-type-exact $object = stdClass */

	$first = $query->first(stdClass::class);
	/** @psalm-check-type-exact $first = stdClass|null */

	$all = $query->all(stdClass::class);
	/** @psalm-check-type-exact $all = list<stdClass> */

	$lazy = $query->lazy(stdClass::class);
	/** @psalm-check-type-exact $lazy = Generator<int, stdClass, mixed, void> */

	return [$folder, $script, $row, $object, $first, $all, $lazy];
}
