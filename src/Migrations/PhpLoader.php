<?php

declare(strict_types=1);

namespace Celema\Quma\Migrations;

use ArgumentCountError;
use Celema\Quma\Contract;
use Celema\Quma\Environment;
use RuntimeException;

final class PhpLoader
{
	/** @var array<string, class-string<Contract\Migration>> */
	private static array $classes = [];

	public function __construct(
		private readonly Environment $env,
		private readonly ?Contract\MigrationFactory $migrationFactory = null,
	) {}

	public function load(string $migration): Contract\Migration
	{
		if (!is_file($migration)) {
			throw new RuntimeException('Could not read migration file');
		}

		$class = self::$classes[$migration] ?? null;

		if ($class === null) {
			$class = $this->loadClass($migration);
			self::$classes[$migration] = $class;
		}

		if ($this->migrationFactory !== null) {
			return $this->migrationFactory->create($class, $this->env);
		}

		try {
			return new $class();
		} catch (ArgumentCountError $e) {
			throw new RuntimeException(
				"Migration {$class} requires constructor arguments, but no migration factory is configured.",
				previous: $e,
			);
		}
	}

	/** @return class-string<Contract\Migration> */
	private function loadClass(string $migration): string
	{
		/** @psalm-suppress UnresolvableInclude */
		$class = require $migration;

		if (!is_string($class) || $class === '') {
			throw new RuntimeException('Invalid migration file. Expected migration class name');
		}

		if (!class_exists($class)) {
			throw new RuntimeException("Invalid migration file. Migration class '{$class}' does not exist");
		}

		if (!is_subclass_of($class, Contract\Migration::class)) {
			throw new RuntimeException(
				"Invalid migration file. Migration class '{$class}' must implement "
				. Contract\Migration::class,
			);
		}

		return $class;
	}
}
