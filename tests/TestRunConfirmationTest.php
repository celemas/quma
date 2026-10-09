<?php

declare(strict_types=1);

namespace Celema\Quma\Tests;

use Celema\Console\Buffer;
use Celema\Console\Io;
use Celema\Quma\Migrations\TestRunConfirmation;

final class TestRunConfirmationTest extends TestCase
{
	public function testYesConfirmsWithoutPrompting(): void
	{
		$buffer = new Buffer(interactive: true);

		$this->assertTrue(new TestRunConfirmation()->confirm(new Io($buffer), yes: true));
		$this->assertStringContainsString('--test-run executes migrations', $buffer->output());
		$this->assertStringNotContainsString('Continue?', $buffer->output());
	}

	public function testNonInteractiveShellsNeedYes(): void
	{
		$buffer = new Buffer("y\n");

		$this->assertFalse(new TestRunConfirmation()->confirm(new Io($buffer), yes: false));
		$this->assertStringContainsString('Use --yes to confirm', $buffer->output());
		$this->assertStringNotContainsString('Continue?', $buffer->output());
	}

	public function testInteractiveShellsAsk(): void
	{
		$buffer = new Buffer("y\n", interactive: true);

		$this->assertTrue(new TestRunConfirmation()->confirm(new Io($buffer), yes: false));
		$this->assertStringEndsWith('Continue? [y/N] ', $buffer->output());
	}

	public function testDeclinedPromptAborts(): void
	{
		$buffer = new Buffer("n\n", interactive: true);

		$this->assertFalse(new TestRunConfirmation()->confirm(new Io($buffer), yes: false));
		$this->assertStringEndsWith("Continue? [y/N] Aborted.\n", $buffer->output());
	}
}
