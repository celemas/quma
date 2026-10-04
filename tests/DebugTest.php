<?php

declare(strict_types=1);

namespace Celema\Quma\Tests;

use Celema\Quma\Args;
use Celema\Quma\Debug;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @internal
 */
final class DebugTest extends TestCase
{
	private const string SQL = 'SELECT * FROM members WHERE member = ?';
	private const string INTERPOLATED = 'SELECT * FROM members WHERE member = 1';

	/** @var list<string> */
	private array $tempDirs = [];

	protected function tearDown(): void
	{
		foreach ($this->tempDirs as $dir) {
			$this->removeDir($dir);
		}

		$this->tempDirs = [];
		parent::tearDown();
	}

	public function testServesAllChannelsForOneQuery(): void
	{
		$translated = $this->tempDir();
		$interpolated = $this->tempDir();

		$output = $this->captureErrorLog(function () use ($translated, $interpolated): void {
			$this->debug([
				'QUMA_DEBUG_PRINT' => '1',
				'QUMA_DEBUG_TRANSLATED' => $translated,
				'QUMA_DEBUG_INTERPOLATED' => $interpolated,
				'QUMA_DEBUG_SESSION' => 'all-channels',
			]);
		});

		$this->assertStringContainsString(self::INTERPOLATED, $output);
		$this->assertSame(['all-channels/0001--members--byId.sql'], $this->files($translated));
		$this->assertSame(self::SQL, file_get_contents($translated . '/all-channels/0001--members--byId.sql'));
		$this->assertSame(['all-channels/0001--members--byId.sql'], $this->files($interpolated));
		$this->assertSame(
			self::INTERPOLATED,
			file_get_contents($interpolated . '/all-channels/0001--members--byId.sql'),
		);
	}

	public function testPrintingDoesNotUseUpFileNumbers(): void
	{
		$translated = $this->tempDir();

		$this->captureErrorLog(function (): void {
			$this->debug(['QUMA_DEBUG_PRINT' => '1', 'QUMA_DEBUG_SESSION' => 'print-then-write']);
		});
		$this->debug(['QUMA_DEBUG_TRANSLATED' => $translated, 'QUMA_DEBUG_SESSION' => 'print-then-write']);

		$this->assertSame(['print-then-write/0001--members--byId.sql'], $this->files($translated));
	}

	public function testPrintsQueryBetweenSeparatorLines(): void
	{
		$output = $this->captureErrorLog(function (): void {
			$this->debug(['QUMA_DEBUG_PRINT' => '1']);
		});

		$this->assertStringContainsString(
			"\n\n" . str_repeat('-', 47) . "\n\n" . self::INTERPOLATED . "\n" . str_repeat('-', 48) . "\n",
			$output,
		);
	}

	public function testInterpolatesEachPositionalPlaceholderInOrder(): void
	{
		$this->assertSame('a = 1 AND b = 2', Debug::interpolate('a = ? AND b = ?', new Args([1, 2])));
	}

	public function testInterpolationKeepsStringLiteralsAndDollarBlocks(): void
	{
		$query = "SELECT 'a?', 'b?', $$ c? $$ WHERE x = ?";

		$this->assertSame(
			"SELECT 'a?', 'b?', $$ c? $$ WHERE x = 1",
			Debug::interpolate($query, new Args([1])),
		);
	}

	public function testReadsSettingsFromServerVariables(): void
	{
		$output = $this->captureErrorLog(function (): void {
			$this->withEnv('QUMA_DEBUG_PRINT', null, function (): void {
				$this->withServer(['QUMA_DEBUG_PRINT' => '1'], function (): void {
					$this->query();
				});
			});
		});

		$this->assertStringContainsString(self::INTERPOLATED, $output);
	}

	public function testServerVariablesTakePrecedenceOverEnvVariables(): void
	{
		$output = $this->captureErrorLog(function (): void {
			$this->withEnv('QUMA_DEBUG_PRINT', null, function (): void {
				$_ENV['QUMA_DEBUG_PRINT'] = '0';

				try {
					$this->withServer(['QUMA_DEBUG_PRINT' => '1'], function (): void {
						$this->query();
					});
				} finally {
					unset($_ENV['QUMA_DEBUG_PRINT']);
				}
			});
		});

		$this->assertStringContainsString(self::INTERPOLATED, $output);
	}

	public function testCreatesGroupWritableSessionDirectories(): void
	{
		$translated = $this->tempDir();

		$this->debug(['QUMA_DEBUG_TRANSLATED' => $translated, 'QUMA_DEBUG_SESSION' => 'permissions']);

		$this->assertSame(0o775 & ~umask(), fileperms($translated . '/permissions') & 0o777);
	}

	public function testResolvesSourcePathsBeforeMakingThemRelative(): void
	{
		$translated = $this->tempDir();
		$sourcePath = $this->sqlDir() . '/members/../members/byId.sql';

		$this->debug(['QUMA_DEBUG_TRANSLATED' => $translated, 'QUMA_DEBUG_SESSION' => 'resolved'], [], $sourcePath);

		$this->assertSame(['resolved/0001--members--byId.sql'], $this->files($translated));
	}

	public function testChangingTheExplicitSessionStartsANewSession(): void
	{
		$translated = $this->tempDir();

		$this->debug(['QUMA_DEBUG_TRANSLATED' => $translated, 'QUMA_DEBUG_SESSION' => 'first-switch']);
		$this->debug(['QUMA_DEBUG_TRANSLATED' => $translated, 'QUMA_DEBUG_SESSION' => 'second-switch']);

		$this->assertSame(
			['first-switch/0001--members--byId.sql', 'second-switch/0001--members--byId.sql'],
			$this->files($translated),
		);
	}

	public function testExplicitSessionLabelsAreSanitizedAndShortened(): void
	{
		$translated = $this->tempDir();
		$long = str_repeat('0123456789', 10);

		$this->debug(['QUMA_DEBUG_TRANSLATED' => $translated, 'QUMA_DEBUG_SESSION' => '.hidden.']);
		$this->debug(['QUMA_DEBUG_TRANSLATED' => $translated, 'QUMA_DEBUG_SESSION' => $long]);

		$this->assertSame(
			[substr($long, 0, 96) . '/0001--members--byId.sql', 'hidden/0001--members--byId.sql'],
			$this->files($translated),
		);
	}

	public function testHttpSessionFallsBackToScriptName(): void
	{
		$session = $this->httpSession([
			'REQUEST_METHOD' => 'get',
			'REQUEST_URI' => null,
			'SCRIPT_NAME' => '/index.php',
			'PHP_SELF' => '/other.php',
		]);

		$this->assertMatchesRegularExpression('/^\d{8}-\d{6}-\d{6}--GET--index\.php--[a-f0-9]{8}$/', $session);
	}

	public function testHttpSessionFallsBackToPhpSelf(): void
	{
		$session = $this->httpSession([
			'REQUEST_URI' => null,
			'SCRIPT_NAME' => null,
			'PHP_SELF' => '/self.php',
		]);

		$this->assertMatchesRegularExpression('/--GET--self\.php--[a-f0-9]{8}$/', $session);
	}

	public function testInvalidRequestTimeFloatFallsBackToRequestTime(): void
	{
		$session = $this->httpSession([
			'REQUEST_TIME_FLOAT' => 'soon',
			'REQUEST_TIME' => '1800000000',
		]);

		$this->assertStringStartsWith('20270115-080000-000000--GET--', $session);
	}

	public function testInvalidRequestTimeFallsBackToCurrentTime(): void
	{
		$session = $this->httpSession([
			'REQUEST_TIME_FLOAT' => null,
			'REQUEST_TIME' => 'soon',
		]);

		$this->assertMatchesRegularExpression('/^\d{8}-\d{6}-\d{6}--GET--/', $session);
	}

	public function testUriWithoutPathIsLabelledRoot(): void
	{
		$session = $this->httpSession(['REQUEST_URI' => '?page=2']);

		$this->assertStringContainsString('--GET--root--', $session);
	}

	public function testUriLabelsAreTrimmedAndShortened(): void
	{
		$long = str_repeat('abcdefghij', 7);

		$this->assertStringContainsString('--GET--admin--', $this->httpSession(['REQUEST_URI' => '/-admin-']));
		$this->assertStringContainsString(
			'--GET--' . substr($long, 0, 64) . '--',
			$this->httpSession(['REQUEST_URI' => '/' . $long]),
		);
	}

	public function testEmptyServerValuesDoNotStartAnHttpSession(): void
	{
		$session = $this->httpSession(['REQUEST_METHOD' => '', 'REQUEST_URI' => '']);

		$this->assertMatchesRegularExpression('/--cli--[a-f0-9]{8}$/', $session);
	}

	public function testCliQueriesShareOneSession(): void
	{
		$translated = $this->tempDir();
		$server = $this->withoutRequest();

		$this->debug(['QUMA_DEBUG_TRANSLATED' => $translated, 'QUMA_DEBUG_SESSION' => null], $server);
		$this->debug(['QUMA_DEBUG_TRANSLATED' => $translated, 'QUMA_DEBUG_SESSION' => null], $server);

		$files = $this->files($translated);
		$this->assertCount(2, $files);
		$this->assertSame(dirname($files[0]), dirname($files[1]));
	}

	/**
	 * Runs one debug query with the given environment and server values.
	 *
	 * @param array<string, string|null> $env
	 * @param array<string, mixed> $server
	 */
	private function debug(array $env, array $server = [], ?string $sourcePath = null): void
	{
		$defaults = [
			'QUMA_DEBUG_PRINT' => null,
			'QUMA_DEBUG_TRANSLATED' => null,
			'QUMA_DEBUG_INTERPOLATED' => null,
			'QUMA_DEBUG_SESSION' => 'default-session',
		];

		$this->withEnvs(array_merge($defaults, $env), function () use ($server, $sourcePath): void {
			$this->withServer($server, function () use ($sourcePath): void {
				$this->query($sourcePath);
			});
		});
	}

	private function query(?string $sourcePath = null): void
	{
		Debug::query(
			$this->getDb(),
			self::SQL,
			new Args([1]),
			$sourcePath ?? $this->sqlDir() . '/members/byId.sql',
		);
	}

	/**
	 * Returns the session directory name of a debug query run as an HTTP request.
	 *
	 * @param array<string, mixed> $server
	 */
	private function httpSession(array $server): string
	{
		$translated = $this->tempDir();
		$server = array_merge(
			$this->withoutRequest(),
			[
				'REQUEST_METHOD' => 'GET',
				'REQUEST_URI' => '/page',
				'REQUEST_TIME_FLOAT' => '1800000000.000000',
			],
			$server,
		);

		$this->debug(['QUMA_DEBUG_TRANSLATED' => $translated, 'QUMA_DEBUG_SESSION' => null], $server);

		$files = $this->files($translated);
		$this->assertCount(1, $files);

		return dirname($files[0]);
	}

	/** @return array<string, null> */
	private function withoutRequest(): array
	{
		return [
			'REQUEST_METHOD' => null,
			'REQUEST_URI' => null,
			'REQUEST_TIME_FLOAT' => null,
			'REQUEST_TIME' => null,
			'SCRIPT_NAME' => null,
			'PHP_SELF' => null,
		];
	}

	/** @param array<string, string|null> $values */
	private function withEnvs(array $values, callable $callback): mixed
	{
		if ($values === []) {
			return $callback();
		}

		$name = (string) array_key_first($values);
		$value = $values[$name];
		unset($values[$name]);

		return $this->withEnv($name, $value, fn(): mixed => $this->withEnvs($values, $callback));
	}

	private function captureErrorLog(callable $callback): string
	{
		$log = self::startErrorLog();

		try {
			$callback();
		} finally {
			$output = self::stopErrorLog($log);
		}

		return $output;
	}

	private function sqlDir(): string
	{
		$dirs = $this->getDb()->getSqlDirs();

		return (string) $dirs[0];
	}

	/** @return list<string> */
	private function files(string $dir): array
	{
		$files = [];
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
		);

		foreach ($iterator as $file) {
			$files[] = substr((string) $file, strlen($dir) + 1);
		}

		sort($files);

		return $files;
	}

	private function tempDir(): string
	{
		$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quma-debug-' . uniqid();
		mkdir($dir, 0o700);
		$this->tempDirs[] = $dir;

		return $dir;
	}

	private function removeDir(string $dir): void
	{
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST,
		);

		foreach ($iterator as $file) {
			$file->isDir() ? rmdir((string) $file) : unlink((string) $file);
		}

		rmdir($dir);
	}
}
