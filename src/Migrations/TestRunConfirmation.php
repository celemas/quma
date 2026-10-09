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

		if (!$io->interactive()) {
			$io->line("\nUse --yes to confirm test-run execution in non-interactive shells.");

			return false;
		}

		if (!$io->confirm('Continue?')) {
			$io->line('Aborted.');

			return false;
		}

		return true;
	}

	private function showWarning(Io $io): void
	{
		$io->line(
			"\n<bright-red>Warning</bright-red>: --test-run executes migrations before rolling the database transaction back.",
		);
		$io->line('SQL migrations are sent to the database.');
		$io->line('TPQL migrations are rendered, so PHP template code runs.');
		$io->line('PHP migrations are required and executed.');
		$io->line('Rollback only covers database changes in the transaction.');
		$io->line(
			'File writes, HTTP calls, queues, emails, logs, cache writes, and other external side effects are not undone.',
		);
	}
}
