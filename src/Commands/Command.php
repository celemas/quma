<?php

declare(strict_types=1);

namespace Celema\Quma\Commands;

use Celema\Console\Command as BaseCommand;
use Celema\Quma\Connection;
use Celema\Quma\Environment;

abstract class Command extends BaseCommand
{
	protected readonly Environment $env;

	/** @param array<non-empty-string, Connection>|Connection $conn */
	public function __construct(array|Connection $conn, array $options = [])
	{
		if (is_array($conn)) {
			$this->env = new Environment($conn, $options);
		} else {
			$this->env = new Environment(['default' => $conn], $options);
		}
	}
}
