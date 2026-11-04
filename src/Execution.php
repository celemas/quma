<?php

declare(strict_types=1);

namespace Celema\Quma;

use Throwable;

/**
 * A statement Quma sent to the database, as passed to the onExecute hook. It
 * holds no bound values, so it is safe to log as long as the SQL is.
 *
 * @api
 */
final readonly class Execution
{
	public function __construct(
		/** The path of the script file, or null for direct SQL. */
		public ?string $script,
		/** The statement after template rendering, before binding. */
		public string $sql,
		/** The seconds the driver took to prepare or execute it. */
		public float $duration,
		/** The driver's row count, or null if it failed or opened a stream. */
		public ?int $rowCount,
		public ?Throwable $error,
	) {}
}
