<?php

/**
 * Runs `db:migrations --apply` in its own process, as a second deployment
 * would: php migrate.php <dsn> <migrations dir> <table>
 */

declare(strict_types=1);

use Celema\Console\Io;
use Celema\Console\Runner;
use Celema\Console\Stdio;
use Celema\Quma\Commands;
use Celema\Quma\Connection;

require __DIR__ . '/../../vendor/autoload.php';

[, $dsn, $migrations, $table] = $argv;
$conn = new Connection($dsn, __DIR__ . '/../sql/default')
	->migrations($migrations)
	->migrationTable($table);

exit(new Runner(Commands::get($conn), new Io(new Stdio('php://output', 'php://output')))->run([
	'run',
	'migrations',
	'--apply',
]));
