<?php

/**
 * Runs `db:migrations --apply` in its own process, as a second deployment
 * would: php migrate.php <dsn> <migrations dir> <table> [<PostgreSQL default isolation level>]
 */

declare(strict_types=1);

use Celema\Console\Io;
use Celema\Console\Runner;
use Celema\Console\Stdio;
use Celema\Quma\Commands;
use Celema\Quma\Connection;
use Celema\Quma\Database;

require __DIR__ . '/../../vendor/autoload.php';

[, $dsn, $migrations, $table] = $argv;
$isolation = $argv[4] ?? '';
$conn = new Connection($dsn, __DIR__ . '/../sql/default')
	->migrations($migrations)
	->migrationTable($table);

if ($isolation !== '') {
	$conn->onConnect(static function (Database $db) use ($isolation): void {
		$db->execute("SET default_transaction_isolation = '{$isolation}'")->run();
	});
}

exit(new Runner(Commands::get($conn), new Io(new Stdio('php://output', 'php://output')))->run([
	'run',
	'migrations',
	'--apply',
]));
