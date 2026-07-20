<?php

declare(strict_types=1);

namespace Celema\Quma\Migrations;

use Celema\Console\Io;

final class TestRunConfirmation
{
	public function confirm(Io $io, bool $yes): bool
	{
		$this->showWarning($io);

		if ($yes) {
			return true;
		}

		if (!$this->inputIsInteractive()) {
			$io->echoln("\nUse --yes to confirm test-run execution in non-interactive shells.");

			return false;
		}

		// Interactive prompting needs a real TTY; non-interactive safety behavior is covered above.
		// @codeCoverageIgnoreStart
		if (!$io->confirm('Continue?')) {
			$io->echoln('Aborted.');

			return false;
		}

		return true;

		// @codeCoverageIgnoreEnd
	}

	private function showWarning(Io $io): void
	{
		$io->echoln(
			"\n<bright-red>Warning</bright-red>: --test-run executes migrations before rolling the database transaction back.",
		);
		$io->echoln('SQL migrations are sent to the database.');
		$io->echoln('TPQL migrations are rendered, so PHP template code runs.');
		$io->echoln('PHP migrations are required and executed.');
		$io->echoln('Rollback only covers database changes in the transaction.');
		$io->echoln(
			'File writes, HTTP calls, queues, emails, logs, cache writes, and other external side effects are not undone.',
		);
	}

	private function inputIsInteractive(): bool
	{
		return defined('STDIN') && stream_isatty(STDIN);
	}
}
