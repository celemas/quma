<?php

declare(strict_types=1);

namespace Celemas\Quma\Migrations;

final class TestRunConfirmation
{
	public function confirm(bool $yes): bool
	{
		$this->showWarning();

		if ($yes) {
			return true;
		}

		if (!$this->inputIsInteractive()) {
			echo "\nUse --yes to confirm test-run execution in non-interactive shells.\n";

			return false;
		}

		// Interactive readline() needs a real TTY; non-interactive safety behavior is covered above.
		// @codeCoverageIgnoreStart
		$answer = readline('Continue? [y/N] ');

		if (!is_string($answer) || !in_array(strtolower(trim($answer)), ['y', 'yes'], true)) {
			echo "Aborted.\n";

			return false;
		}

		return true;

		// @codeCoverageIgnoreEnd
	}

	private function showWarning(): void
	{
		echo
			"\n\033[1;31mWarning\033[0m: --test-run executes migrations before rolling the database transaction back.\n"
		;
		echo "SQL migrations are sent to the database.\n";
		echo "TPQL migrations are rendered, so PHP template code runs.\n";
		echo "PHP migrations are required and executed.\n";
		echo "Rollback only covers database changes in the transaction.\n";
		echo
			"File writes, HTTP calls, queues, emails, logs, cache writes, and other external side effects are not undone.\n"
		;
	}

	private function inputIsInteractive(): bool
	{
		return defined('STDIN') && function_exists('stream_isatty') && stream_isatty(STDIN);
	}
}
