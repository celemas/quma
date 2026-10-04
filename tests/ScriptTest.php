<?php

declare(strict_types=1);

namespace Celema\Quma\Tests;

use Celema\Quma\Args;
use Celema\Quma\Database;
use Celema\Quma\LoadedScript;
use Celema\Quma\Script;
use Celema\Quma\Tests\Util\TestableScript;
use InvalidArgumentException;
use RuntimeException;

/**
 * @internal
 */
class ScriptTest extends TestCase
{
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
