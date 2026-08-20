<?php

declare(strict_types=1);

namespace Celema\Quma\Migrations;

use Celema\Console\Io;
use Celema\Quma\Environment;
use RuntimeException;
use Throwable;

final readonly class Executor
{
	public const string STARTED = 'start';
	public const string ERROR = 'error';
	public const string WARNING = 'warning';
	public const string SUCCESS = 'success';

	public function __construct(
		private Environment $env,
		private Log $log,
		private PhpLoader $phpLoader,
		private Io $io,
	) {}

	public function migrate(string $namespace, string $migration, bool $showStacktrace): string
	{
		$script = file_get_contents($migration);

		if ($script === false) {
			$this->showMessage($migration, new RuntimeException('Could not read migration file'));

			return self::ERROR;
		}

		if (trim($script) === '') {
			$this->showEmptyMessage($migration);

			return self::WARNING;
		}

		return match (pathinfo($migration, PATHINFO_EXTENSION)) {
			'sql' => $this->migrateSQL($namespace, $migration, $script, $showStacktrace),
			'tpql' => $this->migrateTPQL($namespace, $migration, $showStacktrace),
			'php' => $this->migratePHP($namespace, $migration, $showStacktrace),
		};
	}

	private function migrateSQL(
		string $namespace,
		string $migration,
		string $script,
		bool $showStacktrace,
	): string {
		try {
			$script = $this->env->conn->config->placeholders?->compileSql($script, $migration) ?? $script;

			return $this->migrateCompiledSQL($namespace, $migration, $script);
		} catch (Throwable $e) {
			$this->showMessage($migration, $e, $showStacktrace);

			return self::ERROR;
		}
	}

	private function migrateCompiledSQL(
		string $namespace,
		string $migration,
		string $script,
	): string {
		if (trim($script) === '') {
			$this->showEmptyMessage($migration);

			return self::WARNING;
		}

		$db = $this->env->db;
		$db->execute($script)->run();
		$this->log->record($db, $namespace, $migration);
		$this->showMessage($migration);

		return self::SUCCESS;
	}

	private function migrateTPQL(
		string $namespace,
		string $migration,
		bool $showStacktrace,
	): string {
		try {
			$db = $this->env->db;
			$conn = $this->env->conn;
			$context = [
				'driver' => $db->getPdoDriver(),
				'db' => $db,
				'conn' => $conn,
			];

			$executeTemplate = static function (
				string $templatePath,
				array $context,
			): void {
				extract($context, EXTR_SKIP);

				/** @psalm-suppress UnresolvableInclude */
				require $templatePath;
			};

			ob_start();
			$script = '';

			try {
				$executeTemplate($migration, $context);
				$script = ob_get_contents();
			} finally {
				ob_end_clean();
			}

			if (!is_string($script)) {
				// Defensive guard for an impossible false from ob_get_contents() after ob_start().
				$script = ''; // @codeCoverageIgnore
			}

			$script = $conn->config->placeholders?->compileSql($script, $migration) ?? $script;

			if (trim($script) === '') {
				$this->showEmptyMessage($migration);

				return self::WARNING;
			}

			return $this->migrateCompiledSQL($namespace, $migration, $script);
		} catch (Throwable $e) {
			$this->showMessage($migration, $e, $showStacktrace);

			return self::ERROR;
		}
	}

	private function migratePHP(
		string $namespace,
		string $migration,
		bool $showStacktrace,
	): string {
		try {
			$migrationObject = $this->phpLoader->load($migration);
			$migrationObject->run($this->env);
			$this->log->record($this->env->db, $namespace, $migration);
			$this->showMessage($migration);

			return self::SUCCESS;
		} catch (Throwable $e) {
			$this->showMessage($migration, $e, $showStacktrace);

			return self::ERROR;
		}
	}

	private function showEmptyMessage(string $migration): void
	{
		$this->io->echolnErr(
			"<yellow>Warning</yellow>: Migration '<bright-yellow>"
				. basename($migration)
				. "</bright-yellow>' is empty. Skipped",
		);
	}

	private function showMessage(
		string $migration,
		?Throwable $e = null,
		bool $showStacktrace = false,
	): void {
		$io = $this->io;

		if ($e) {
			$io->echolnErr(
				"<bright-red>Error</bright-red>: while working on migration '<bright-yellow>"
					. basename($migration)
					. "</bright-yellow>'",
			);
			$io->echolnErr($io->escape($e->getMessage()));

			if ($showStacktrace) {
				$io->echolnErr($io->escape($e->getTraceAsString()));
			}

			return;
		}

		$io->echoln(
			"<bright-green>Success</bright-green>: Migration '<bright-yellow>"
				. basename($migration)
				. "</bright-yellow>' successfully applied",
		);
	}
}
