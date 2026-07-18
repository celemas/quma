<?php

declare(strict_types=1);

namespace Celema\Quma\Migrations;

final readonly class Planner
{
	public function __construct(
		private DriverPolicy $driverPolicy,
	) {}

	public function migrationId(string $namespace, string $migration): string
	{
		$name = basename($migration);

		if ($namespace === 'default') {
			return $name;
		}

		return $namespace . ':' . $name;
	}

	/**
	 * @param list<string> $migrations
	 * @param list<string> $appliedMigrations
	 *
	 * @return list<string>
	 */
	public function pendingMigrations(
		string $namespace,
		array $migrations,
		array $appliedMigrations,
	): array {
		$pending = [];

		foreach ($migrations as $migration) {
			assert($migration !== '', 'Migration path must be a non-empty string.');

			if (in_array($this->migrationId($namespace, $migration), $appliedMigrations, strict: true)) {
				continue;
			}

			if (!$this->driverPolicy->supportsMigration($migration)) {
				continue;
			}

			$pending[] = $migration;
		}

		return $pending;
	}

	/**
	 * @param list<string> $migrations
	 *
	 * @return array<string, list<string>>
	 */
	public function duplicateMigrationIds(string $namespace, array $migrations): array
	{
		$pathsById = [];

		foreach ($migrations as $migration) {
			assert($migration !== '', 'Migration path must be a non-empty string.');

			if (!$this->driverPolicy->supportsMigration($migration)) {
				continue;
			}

			$pathsById[$this->migrationId($namespace, $migration)][] = $migration;
		}

		return array_filter($pathsById, static fn(array $paths): bool => count($paths) > 1);
	}
}
