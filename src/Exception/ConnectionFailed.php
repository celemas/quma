<?php

declare(strict_types=1);

namespace Celema\Quma\Exception;

use PDOException;

/** @api */
final class ConnectionFailed extends PDOException
{
	public static function from(PDOException $e): self
	{
		$failure = new self('Could not connect to the database: ' . $e->getMessage(), previous: $e);
		$failure->code = $e->getCode();
		$failure->errorInfo = $e->errorInfo;

		return $failure;
	}
}
