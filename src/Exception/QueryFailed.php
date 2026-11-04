<?php

declare(strict_types=1);

namespace Celema\Quma\Exception;

use Celema\Quma\DriverError;
use PDOException;

/**
 * A statement or transaction call the database refused. Keeps the message, code, and
 * `errorInfo` of the driver's exception, so that `catch (PDOException)` keeps working.
 *
 * @api
 */
class QueryFailed extends PDOException
{
	/** @internal */
	final public function __construct(
		PDOException $e,
		public readonly ?string $script = null,
		public readonly ?string $constraint = null,
	) {
		parent::__construct($e->getMessage(), previous: $e);

		$this->code = $e->getCode();
		$this->errorInfo = $e->errorInfo;
	}

	/** @internal */
	public static function from(PDOException $e, string $driver, ?string $script): self
	{
		$error = new DriverError($driver, $e);
		$class = $error->exception();

		return new $class($e, $script, $error->constraint());
	}
}
