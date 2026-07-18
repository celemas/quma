<?php

declare(strict_types=1);

namespace Celema\Quma\Tests;

use Celema\Quma\Migrations\DriverPolicy;
use Celema\Quma\Migrations\Planner;
use RuntimeException;

/**
 * @internal
 */
class MigrationPlannerTest extends TestCase
{
	public function testDriverPolicyDetectsKnownDrivers(): void
	{
		$this->assertTrue(new DriverPolicy('sqlite')->isKnown());
		$this->assertTrue(new DriverPolicy('mysql')->isKnown());
		$this->assertTrue(new DriverPolicy('pgsql')->isKnown());
		$this->assertFalse(new DriverPolicy('oci')->isKnown());
	}

	public function testDriverPolicyReportsTransactionSupport(): void
	{
		$this->assertTrue(new DriverPolicy('sqlite')->supportsTransactions());
		$this->assertTrue(new DriverPolicy('pgsql')->supportsTransactions());
		$this->assertFalse(new DriverPolicy('mysql')->supportsTransactions());
	}

	public function testDriverPolicyRejectsUnknownTransactionSupport(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Database driver not supported');

		new DriverPolicy('oci')->supportsTransactions();
	}

	public function testDriverPolicyFiltersDriverSpecificMigrations(): void
	{
		$policy = new DriverPolicy('sqlite');

		$this->assertTrue($policy->supportsMigration('/migrations/000001-users.sql'));
		$this->assertTrue($policy->supportsMigration('/migrations/000002-users-[sqlite].sql'));
		$this->assertFalse($policy->supportsMigration('/migrations/000003-users-[pgsql].sql'));
		$this->assertTrue($policy->supportsMigration('/migrations/[pgsql]/000004-users.sql'));
		$this->assertTrue($policy->supportsMigration('/migrations/000005-users-[archived].sql'));
	}

	public function testPlannerBuildsMigrationIds(): void
	{
		$planner = new Planner(new DriverPolicy('sqlite'));

		$this->assertSame('000001-users.sql', $planner->migrationId('default', '/app/000001-users.sql'));
		$this->assertSame('cms:000001-users.sql', $planner->migrationId('cms', '/app/000001-users.sql'));
	}

	public function testPlannerFindsPendingMigrations(): void
	{
		$planner = new Planner(new DriverPolicy('sqlite'));

		$result = $planner->pendingMigrations(
			'default',
			[
				'000001-applied.sql',
				'000002-pgsql-[pgsql].sql',
				'000003-sqlite-[sqlite].sql',
			],
			['000001-applied.sql'],
		);

		$this->assertSame(['000003-sqlite-[sqlite].sql'], $result);
	}

	public function testPlannerFindsDuplicateSupportedMigrationIds(): void
	{
		$planner = new Planner(new DriverPolicy('sqlite'));

		$result = $planner->duplicateMigrationIds('cms', [
			'/a/000001-users.sql',
			'/b/000001-users.sql',
			'/c/000002-pgsql-[pgsql].sql',
			'/d/000003-roles.sql',
		]);

		$this->assertSame(
			[
				'cms:000001-users.sql' => [
					'/a/000001-users.sql',
					'/b/000001-users.sql',
				],
			],
			$result,
		);
	}
}
