<?php

declare(strict_types=1);

namespace Celema\Quma\Tests;

use Celema\Quma\Args;
use Celema\Quma\Database;
use Celema\Quma\LoadedScript;
use Celema\Quma\Script;
use Celema\Quma\Tests\Util\TestableScript;
use InvalidArgumentException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * @internal
 */
class ScriptTest extends TestCase
{
	#[DataProvider('parameterContexts')]
	public function testFindsParametersOutsideLiteralsAndComments(string $sql): void
	{
		foreach (self::getAvailableDsns() as $dsn) {
			$script = new Script(
				new Database($this->connection(dsn: $dsn)),
				new LoadedScript($sql, 'inline.tpql'),
				true,
			);

			$record = $script->invoke(['x' => 'bound', 'y' => 'unused'])->one();

			$this->assertSame('bound', $record['v'], $dsn);
		}
	}

	/** @return array<string, array{string}> */
	public static function parameterContexts(): array
	{
		return [
			'apostrophe in line comment' => ["-- it's\nSELECT :x AS v WHERE 'a' = 'a'"],
			'apostrophe in block comment' => ["/* it's */\nSELECT :x AS v WHERE 'a' = 'a'"],
			'apostrophes in comments around parameter' => ["-- it's\nSELECT :x AS v\n-- it's\n"],
			'double quote in comment' => ["-- 5\" wide\nSELECT :x AS v, 1 AS \"n\""],
			'dollars in comment' => ["-- $$\nSELECT :x AS v, '$$' AS s"],
			'comment markers in strings' => ["SELECT '--' AS a, '/*' AS b, :x AS v, '*/' AS c"],
			'dollars in strings' => ["SELECT '$$' AS a, :x AS v, '$$' AS b"],
			'doubled quote in string' => ["SELECT 'it''s' AS s, :x AS v"],
			'parameter name in line comment' => ["-- :y\nSELECT :x AS v"],
			'parameter name in block comment' => ["/* :y */\nSELECT :x AS v"],
			'parameter name in string' => ["SELECT ':y' AS s, :x AS v"],
			'parameter name in double quotes' => ['SELECT 1 AS ":y", :x AS v'],
		];
	}

	public function testIgnoresParameterNamesInDollarBlocks(): void
	{
		$dsns = array_filter(
			self::getAvailableDsns(),
			static fn(string $dsn): bool => str_starts_with($dsn, 'pgsql:'),
		);

		if ($dsns === []) {
			$this->markTestSkipped('Dollar-quoted blocks require PostgreSQL.');
		}

		$script = new Script(
			new Database($this->connection(dsn: array_first($dsns))),
			new LoadedScript('SELECT $$ :y $$ AS s, :x AS v', 'inline.tpql'),
			true,
		);

		$record = $script->invoke(['x' => 'bound', 'y' => 'unused'])->one();

		$this->assertSame('bound', $record['v']);
	}

	public function testFindsAParameterAtTheStartOfATemplate(): void
	{
		$script = new Script(
			new Database($this->connection()),
			new LoadedScript(':x IS NULL', 'inline.tpql'),
			true,
		);

		$this->expectException(PDOException::class);
		$this->expectExceptionMessageMatches('/near ":x"/');

		$script->invoke(['x' => 1])->run();
	}

	#[DataProvider('driverSyntax')]
	public function testReadsLiteralsAndCommentsByDriver(string $driver, string $sql): void
	{
		$dsns = array_filter(
			self::getAvailableDsns(),
			static fn(string $dsn): bool => str_starts_with($dsn, "{$driver}:"),
		);

		if ($dsns === []) {
			$this->markTestSkipped("{$driver} is not available.");
		}

		$script = new Script(
			new Database($this->connection(dsn: array_first($dsns))),
			new LoadedScript($sql, 'inline.tpql'),
			true,
		);

		$record = $script->invoke(['x' => 'bound', 'y' => 'unused'])->one();

		$this->assertSame('bound', $record['v']);
	}

	/** @return array<string, array{string, string}> */
	public static function driverSyntax(): array
	{
		return [
			'mysql hash comment' => ['mysql', "# it's\nSELECT :x AS v WHERE 'a' = 'a'"],
			'mysql parameter name in hash comment' => ['mysql', "# :y\nSELECT :x AS v"],
			'mysql backslash in single quotes' => ['mysql', "SELECT 'it\\'s' AS s, :x AS v WHERE 'a' = 'a'"],
			'mysql backslash in double quotes' => ['mysql', "SELECT \"it\\\"s\" AS s, :x AS v WHERE 'a' = 'a'"],
			'mysql dashes without whitespace' => ['mysql', 'SELECT 5--:x AS n, :x AS v'],
			'mysql dashes at the end' => ['mysql', 'SELECT :x AS v --'],
			'mysql escaped backslash' => ['mysql', "SELECT 'C:\\\\' AS s, :x AS v, 'a' AS a"],
			'pgsql escape string' => ['pgsql', "SELECT E'it\\'s' AS s, :x AS v, 'a' AS a"],
			'pgsql escape string with doubled quote' => ['pgsql', "SELECT e'a''b\\'c' AS s, :x AS v, 'a' AS a"],
			'pgsql backslash in standard string' => ['pgsql', "SELECT 'C:\\' AS s, :x AS v, 'a' AS a"],
			'pgsql tagged block' => ['pgsql', 'SELECT $t$ :y $t$ AS s, :x AS v'],
			'pgsql plain block in tagged block' => ['pgsql', 'SELECT $a$ $$ :y $$ $a$ AS s, :x AS v'],
			'pgsql tag in comment' => ['pgsql', "-- \$t\$\nSELECT :x AS v, '\$t\$' AS s"],
			'pgsql hash operator' => ['pgsql', 'SELECT 1 # 3 AS n, :x AS v'],
			'sqlite backslash in string' => ['sqlite', "SELECT 'C:\\' AS s, :x AS v, 'a' AS a"],
		];
	}

	#[DataProvider('largeLiterals')]
	public function testFindsParametersAfterLargeLiterals(string $sql): void
	{
		$script = new Script(
			new Database($this->connection()),
			new LoadedScript($sql, 'inline.tpql'),
			true,
		);

		$record = $script->invoke(['x' => 'bound'])->one();

		$this->assertSame('bound', $record['v']);
	}

	/** @return array<string, array{string}> */
	public static function largeLiterals(): array
	{
		$large = str_repeat('x', 2_000_000);

		return [
			'block comment' => ["/* {$large} */ SELECT :x AS v"],
			'line comment' => ["-- {$large}\nSELECT :x AS v"],
			'string' => ["SELECT '{$large}' AS s, :x AS v"],
		];
	}

	public function testThrowsWhenParametersCannotBeDetermined(): void
	{
		$script = new Script(
			new Database($this->connection()),
			new LoadedScript('/* comment */ SELECT :x AS v', 'inline.tpql'),
			true,
		);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not find the parameters of inline.tpql');

		$this->withoutBacktracking(static fn() => $script->invoke(['x' => 'bound']));
	}

	public function testEvaluateTemplateRendersTemplateFile(): void
	{
		$template = tempnam(sys_get_temp_dir(), 'quma-template-');
		assert(is_string($template), 'Template path must be available.');
		file_put_contents($template, 'Hello <?= $name ?> from <?= $pdodriver ?>');

		try {
			$script = new TestableScript(
				new Database($this->connection()),
				new LoadedScript($template, $template),
				true,
			);
			$result = $script->evaluateTemplatePublic($template, new Args([['name' => 'Chuck']]));

			$this->assertSame('Hello Chuck from sqlite', $result);
		} finally {
			if (is_file($template)) {
				unlink($template);
			}
		}
	}

	public function testEvaluateTemplateRendersLoadedSource(): void
	{
		$script = new TestableScript(
			new Database($this->connection()),
			new LoadedScript('Hello <?= $name ?> from <?= $pdodriver ?>', 'inline.tpql'),
			true,
		);

		$result = $script->evaluateTemplatePublic(
			'Hello <?= $name ?> from <?= $pdodriver ?>',
			new Args([['name' => 'Chuck']]),
		);

		$this->assertSame('Hello Chuck from sqlite', $result);
	}

	public function testEvaluateTemplateRejectsReservedTemplateParameters(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("Template parameter 'pdodriver' is reserved.");

		$script = new TestableScript(
			new Database($this->connection()),
			new LoadedScript('Hello from <?= $pdodriver ?>', 'inline.tpql'),
			true,
		);

		$script->evaluateTemplatePublic(
			'Hello from <?= $pdodriver ?>',
			new Args([['pdodriver' => 'evil']]),
		);
	}

	public function testEvaluateTemplateCleansBufferWhenTemplateThrows(): void
	{
		$template = tempnam(sys_get_temp_dir(), 'quma-template-');
		assert(is_string($template), 'Template path must be available.');
		file_put_contents($template, "before<?php throw new \\RuntimeException('template failed'); ?>");
		$level = ob_get_level();

		try {
			$this->expectException(RuntimeException::class);
			$this->expectExceptionMessage('template failed');

			$script = new TestableScript(
				new Database($this->connection()),
				new LoadedScript($template, $template),
				true,
			);
			$script->evaluateTemplatePublic($template, new Args([]));
		} finally {
			$this->assertSame($level, ob_get_level());

			if (is_file($template)) {
				unlink($template);
			}
		}
	}

	public function testEvaluateTemplateReturnsEmptyStringForMissingFile(): void
	{
		$missingFile = sys_get_temp_dir() . '/quma-missing-template-' . uniqid() . '.tpql';

		if (is_file($missingFile)) {
			unlink($missingFile);
		}

		$script = new TestableScript(
			new Database($this->connection()),
			new LoadedScript($missingFile, $missingFile),
			true,
		);

		$this->assertSame('', $script->evaluateTemplatePublic($missingFile, new Args([])));
	}

	public function testRenderingTemplateSourceRemovesItsTemporaryFile(): void
	{
		$before = $this->temporaryTemplates();
		$script = new Script(
			new Database($this->connection()),
			new LoadedScript('SELECT <?= 1 ?> AS value', '/virtual.tpql'),
			true,
		);

		$this->assertSame('SELECT 1 AS value', (string) $script->invoke());
		$this->assertSame($before, $this->temporaryTemplates());
	}

	public function testFailingTemplateSourceRemovesItsTemporaryFile(): void
	{
		$before = $this->temporaryTemplates();
		$script = new Script(
			new Database($this->connection()),
			new LoadedScript('<?php throw new RuntimeException("boom");', '/virtual.tpql'),
			true,
		);

		try {
			$script->invoke();
			$this->fail('RuntimeException was not thrown');
		} catch (RuntimeException $e) {
			$this->assertSame('boom', $e->getMessage());
		}

		$this->assertSame($before, $this->temporaryTemplates());
	}

	/** @return list<string> */
	private function temporaryTemplates(): array
	{
		return (array) glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quma-tpql-*');
	}
}
