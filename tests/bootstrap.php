<?php

declare(strict_types=1);

use Celemas\Quma\Tests\TestCase;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Util/noninteractive.php';

$clean = static function (): void {
	$migrationsDir = __DIR__ . '/migrations/';
	$paths = glob("{$migrationsDir}*test-migration*");

	if ($paths !== false) {
		foreach ($paths as $path) {
			if (!is_file($path)) {
				continue;
			}

			unlink($path);
		}
	}

	TestCase::cleanUpTestDbs();
};

$clean();

register_shutdown_function($clean);
