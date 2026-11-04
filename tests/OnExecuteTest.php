<?php

declare(strict_types=1);

namespace Celema\Quma\Tests;

use Celema\Quma\Database;
use Celema\Quma\Exception\QueryFailed;
use Celema\Quma\Execution;
use Celema\Quma\LoadedScript;
use Celema\Quma\Script;
use LogicException;

/**
 * @internal
 */
final class OnExecuteTest extends TestCase
{
	/** @var list<Execution> */
	private array $executions = [];

	public function testReportsAStatement(): void
	{
		$db = $this->db();

		$db->execute('SELECT 1 AS v')->all();

		$this->assertCount(1, $this->executions);
		$execution = $this->executions[0];
		$this->assertNull($execution->script);
		$this->assertSame('SELECT 1 AS v', $execution->sql);
		$this->assertGreaterThan(0.0, $execution->duration);
		$this->assertSame(0, $execution->rowCount);
		$this->assertNull($execution->error);
	}

	public function testReportsEveryRun(): void
	{
		$query = $this->db()->execute('SELECT 1 AS v');
		$query->first();
		$query->all();
		$query->run();
		$query->len();

		$this->assertCount(3, $this->executions);
	}

	public function testReportsTheScriptAndRenderedSqlWithoutValues(): void
	{
		$script = new Script(
			$this->db(),
			new LoadedScript('SELECT <?= 1 + 1 ?> AS two, :hidden AS s', 'users/template.tpql'),
			true,
		);

		$script->invoke(['hidden' => 'value only the database may see'])->one();

		$execution = $this->executions[0];
		$this->assertSame('users/template.tpql', $execution->script);
		$this->assertSame('SELECT 2 AS two, :hidden AS s', $execution->sql);
		$this->assertStringNotContainsString('value only', print_r($execution, true));
	}

	public function testReportsAFailedStatementAndStillThrowsIt(): void
	{
		$db = $this->db();

		try {
			$db->execute('SELECT * FROM quma_missing_table')->all();
			$this->fail('Expected the statement to fail.');
		} catch (QueryFailed $e) {
			$this->assertCount(1, $this->executions);
			$this->assertSame($e, $this->executions[0]->error);
			$this->assertNull($this->executions[0]->rowCount);
		}
	}

	public function testReportsTheRowCountOfAWrite(): void
	{
		foreach (self::getAvailableDsns() as $dsn) {
			$this->executions = [];
			$db = $this->db($dsn);
			$db->execute('CREATE TEMPORARY TABLE quma_hook (id INT)')->run();
			$db->execute('INSERT INTO quma_hook (id) VALUES (1), (2)')->run();

			$this->assertSame(2, $this->executions[1]->rowCount, $dsn);
		}
	}

	public function testReportsTheOpeningOfAStreamOnce(): void
	{
		foreach (self::getAvailableDsns() as $dsn) {
			$this->executions = [];
			$db = $this->db($dsn);

			iterator_to_array($db->execute('SELECT 1 AS v')->stream());

			$this->assertCount(1, $this->executions, $dsn);
			$this->assertNull($this->executions[0]->rowCount);
			$this->assertNull($this->executions[0]->error);
		}
	}

	public function testReportsAStreamThatFailsToOpen(): void
	{
		$db = $this->db();

		try {
			$db->execute('SELECT 1 AS v');
			$this->executions = [];
			$db->execute('SELECT * FROM quma_missing_table')->stream()->current();
			$this->fail('Expected the statement to fail.');
		} catch (QueryFailed $e) {
			$this->assertSame($e, $this->executions[0]->error);
		}
	}

	public function testDoesNotReportTransactionCalls(): void
	{
		$db = $this->db();

		$db->begin();
		$db->commit();
		$db->transaction(static fn(): int => 1);
		$db->transaction(static fn(): mixed => $db->transaction(static fn(): int => 1));

		$this->assertSame([], $this->executions);
	}

	public function testLogsAFailingHookAndKeepsTheStatementsResult(): void
	{
		$db = new Database($this->connection()->onExecute(static function (): void {
			throw new LogicException('broken hook');
		}));
		$log = self::startErrorLog();

		try {
			$row = $db->execute('SELECT 1 AS v')->one();
		} finally {
			$output = self::stopErrorLog($log);
		}

		$this->assertSame(['v' => 1], $row);
		$this->assertStringContainsString('Quma: the onExecute hook failed: broken hook', $output);
	}

	public function testReportingWorksAgainAfterAFailingHook(): void
	{
		$calls = 0;
		$db = new Database($this->connection()->onExecute(static function () use (&$calls): void {
			$calls++;

			throw new LogicException('broken hook');
		}));
		$log = self::startErrorLog();

		try {
			$db->execute('SELECT 1 AS v')->run();
			$db->execute('SELECT 1 AS v')->run();
		} finally {
			self::stopErrorLog($log);
		}

		$this->assertSame(2, $calls);
	}

	public function testStatementsOfTheHookAreNotReported(): void
	{
		$calls = 0;
		$db = null;
		$db = new Database($this->connection()->onExecute(static function () use (&$calls, &$db): void {
			$calls++;
			$db->execute('SELECT 2 AS v')->all();
		}));

		$db->execute('SELECT 1 AS v')->all();

		$this->assertSame(1, $calls);
	}

	private function db(?string $dsn = null): Database
	{
		return new Database($this->connection(dsn: $dsn)->onExecute(function (Execution $execution): void {
			$this->executions[] = $execution;
		}));
	}
}
