<?php

declare(strict_types=1);

namespace Celemas\Quma\Migrations;

use RuntimeException;

final readonly class DriverPolicy
{
	private const array KNOWN_DRIVERS = ['sqlite', 'mysql', 'pgsql'];

	public function __construct(
		private string $driver,
	) {}

	public function isKnown(): bool
	{
		return in_array($this->driver, self::KNOWN_DRIVERS, strict: true);
	}

	public function supportsTransactions(): bool
	{
		return match ($this->driver) {
			'sqlite', 'pgsql' => true,
			'mysql' => false,
			default => throw new RuntimeException('Database driver not supported'),
		};
	}

	public function supportsMigration(string $migration): bool
	{
		$file = basename($migration);
		$scoped = false;

		foreach (self::KNOWN_DRIVERS as $driver) {
			if (!str_contains($file, "[{$driver}]")) {
				continue;
			}

			if ($driver === $this->driver) {
				return true;
			}

			$scoped = true;
		}

		return !$scoped;
	}
}
