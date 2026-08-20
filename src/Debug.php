<?php

declare(strict_types=1);

namespace Celema\Quma;

use DateTimeImmutable;
use RuntimeException;

/**
 * @internal
 *
 * Coverage ignores mark defensive filesystem/global-state race branches that are
 * not deterministic or meaningful to exercise in tests.
 */
final class Debug
{
	public const string ENV_DEBUG = 'QUMA_DEBUG';
	public const string ENV_PRINT = 'QUMA_DEBUG_PRINT';
	public const string ENV_TRANSLATED = 'QUMA_DEBUG_TRANSLATED';
	public const string ENV_INTERPOLATED = 'QUMA_DEBUG_INTERPOLATED';

	private static ?string $sessionKey = null;
	private static ?string $session = null;
	private static ?string $fallbackTime = null;
	private static int $counter = 0;

	public static function query(Database $db, string $query, Args $args, ?string $sourcePath): void
	{
		$print = self::prints();
		$writeTranslated = self::writesTranslated();
		$writeInterpolated = self::writesInterpolated();

		if (!$print && !$writeTranslated && !$writeInterpolated) {
			return;
		}

		$path = $writeTranslated || $writeInterpolated
			? self::sessionPath($sourcePath, $db->getSqlDirs())
			: null;

		if ($writeTranslated) {
			self::writeEnv(self::ENV_TRANSLATED, $path, $query);
		}

		if (!$print && !$writeInterpolated) {
			return;
		}

		$interpolated = self::interpolate($query, $args);

		if ($print) {
			self::printQuery($interpolated);
		}

		if ($writeInterpolated) {
			self::writeEnv(self::ENV_INTERPOLATED, $path, $interpolated);
		}
	}

	public static function interpolate(string $query, Args $args): string
	{
		$prep = self::prepareQuery($query);

		if ($args->type() === ArgType::Named) {
			$interpolated = self::interpolateNamed($prep->query, $args->getNamed());
		} else {
			$interpolated = self::interpolatePositional($prep->query, $args->get());
		}

		return self::restoreQuery($interpolated, $prep);
	}

	public static function enabled(): bool
	{
		$value = self::env(self::ENV_DEBUG);

		return $value !== null && self::flag($value);
	}

	public static function prints(): bool
	{
		$value = self::env(self::ENV_PRINT);

		return $value !== null && self::flag($value);
	}

	public static function writesTranslated(): bool
	{
		return self::env(self::ENV_TRANSLATED) !== null;
	}

	public static function writesInterpolated(): bool
	{
		return self::env(self::ENV_INTERPOLATED) !== null;
	}

	private static function printQuery(string $query): void
	{
		$msg =
			"\n\n-----------------------------------------------\n\n"
			. $query
			. "\n------------------------------------------------\n";

		if (($_SERVER['SERVER_SOFTWARE'] ?? null) !== null) {
			// @codeCoverageIgnoreStart
			error_log($msg);

			// @codeCoverageIgnoreEnd
		} else {
			echo $msg;
		}
	}

	private static function value(mixed $value): string
	{
		if (is_string($value)) {
			return "'" . $value . "'";
		}

		if (is_array($value)) {
			$encoded = json_encode($value);

			return "'" . ($encoded !== false ? $encoded : '[]') . "'";
		}

		if (is_null($value)) {
			return 'NULL';
		}

		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}

		return (string) $value;
	}

	private static function prepareQuery(string $query): PreparedQuery
	{
		$patterns = [
			Query::PATTERN_BLOCK,
			Query::PATTERN_STRING,
			Query::PATTERN_COMMENT_MULTI,
			Query::PATTERN_COMMENT_SINGLE,
		];

		$swaps = [];
		$i = 0;

		do {
			$found = false;

			foreach ($patterns as $pattern) {
				$matches = [];

				if (preg_match($pattern, $query, $matches) === 1) {
					$match = $matches[0];
					$replacement = "___CHUCK_REPLACE_{$i}___";
					assert($match !== '', 'Query placeholder match must not be empty.');
					$swaps[$replacement] = $match;

					$query = preg_replace($pattern, $replacement, $query, limit: 1) ?? $query;
					$found = true;
					$i++;

					break;
				}
			}
		} while ($found);

		return new PreparedQuery($query, $swaps);
	}

	private static function restoreQuery(string $query, PreparedQuery $prep): string
	{
		foreach ($prep->swaps as $swap => $replacement) {
			$query = str_replace($swap, $replacement, $query);
		}

		return $query;
	}

	/** @param array<array-key, mixed> $args */
	private static function interpolateNamed(string $query, array $args): string
	{
		$map = [];

		array_walk(
			$args,
			static function (mixed $value, int|string $key) use (&$map): void {
				if (is_string($key) && $key !== '') {
					$map[':' . $key] = self::value($value);
				}
			},
		);

		return strtr($query, $map);
	}

	/** @param array<array-key, mixed> $args */
	private static function interpolatePositional(string $query, array $args): string
	{
		$result = $query;

		array_walk(
			$args,
			static function (mixed $value) use (&$result): void {
				$replaced = preg_replace('/\\?/', self::value($value), $result, 1);
				$result = $replaced ?? $result;
			},
		);

		return $result;
	}

	private static function flag(string $value): bool
	{
		return match (strtolower($value)) {
			'1', 'true', 'yes', 'on' => true,
			default => false,
		};
	}

	private static function env(string $name): ?string
	{
		$value = getenv($name);

		if ($value === false) {
			$value = $_SERVER[$name] ?? $_ENV[$name] ?? null;
		}

		if (!is_string($value)) {
			return null;
		}

		$value = trim($value);

		return $value === '' ? null : $value;
	}

	/** @return non-empty-string|null */
	private static function dir(string $name): ?string
	{
		$dir = self::env($name);

		if ($dir === null) {
			return null; // @codeCoverageIgnore
		}

		if (!is_dir($dir)) {
			throw new RuntimeException("Quma debug directory does not exist for {$name}: {$dir}");
		}

		if (!is_writable($dir)) {
			throw new RuntimeException("Quma debug directory is not writable for {$name}: {$dir}"); // @codeCoverageIgnore
		}

		$path = realpath($dir);

		if ($path === false || $path === '') {
			throw new RuntimeException("Quma debug directory does not exist for {$name}: {$dir}"); // @codeCoverageIgnore
		}

		return $path;
	}

	private static function writeEnv(string $name, ?string $relative, string $source): void
	{
		if ($relative === null) {
			return; // @codeCoverageIgnore
		}

		$dir = self::dir($name);

		if ($dir === null) {
			return; // @codeCoverageIgnore
		}

		self::write($dir, $relative, $source);
	}

	private static function write(
		string $dir,
		string $relative,
		string $source,
	): void {
		$path = $dir . DIRECTORY_SEPARATOR . self::safeRelativePath($relative);
		$targetDir = dirname($path);

		if (!is_dir($targetDir) && !mkdir($targetDir, 0o775, true) && !is_dir($targetDir)) {
			throw new RuntimeException('Could not create Quma debug directory: ' . $targetDir); // @codeCoverageIgnore
		}

		if (file_put_contents($path, $source, LOCK_EX) === false) {
			throw new RuntimeException('Could not write Quma debug file: ' . $path); // @codeCoverageIgnore
		}
	}

	/** @param array<array-key, mixed> $roots */
	private static function relativeToRoots(string $sourcePath, array $roots): string
	{
		$realSource = realpath($sourcePath);
		$source = $realSource !== false ? $realSource : $sourcePath;

		foreach ($roots as $root) {
			if (!is_string($root) || $root === '') {
				continue; // @codeCoverageIgnore
			}

			$realRoot = realpath($root);
			$root = $realRoot !== false ? $realRoot : $root;
			$prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

			if (str_starts_with($source, $prefix)) {
				$relative = substr($source, strlen($prefix));

				return $relative === '' ? basename($sourcePath) : $relative;
			}
		}

		return basename($sourcePath); // @codeCoverageIgnore
	}

	/** @param array<array-key, mixed> $roots */
	private static function sessionPath(?string $sourcePath, array $roots): string
	{
		return self::session()
			. DIRECTORY_SEPARATOR
			. self::counter()
			. '--'
			. self::sourceName($sourcePath, $roots);
	}

	/** @param array<array-key, mixed> $roots */
	private static function sourceName(?string $sourcePath, array $roots): string
	{
		if ($sourcePath === null) {
			return 'execute.sql';
		}

		$relative = self::relativeToRoots($sourcePath, $roots);
		$dir = dirname($relative);
		$name = pathinfo($relative, PATHINFO_FILENAME);
		$name = $name !== '' ? $name : 'query';
		$path = ($dir === '.' ? '' : $dir . DIRECTORY_SEPARATOR) . $name . '.sql';

		return preg_replace('/[\\/]+/', '--', $path) ?? 'query.sql';
	}

	private static function session(): string
	{
		[$key, $session] = self::sessionInfo();

		if (self::$sessionKey !== $key) {
			self::$sessionKey = $key;
			self::$session = $session;
			self::$counter = 0;
		}

		return self::$session ?? $session;
	}

	/** @return array{0: string, 1: string} */
	private static function sessionInfo(): array
	{
		$explicit = self::env('QUMA_DEBUG_SESSION');

		if ($explicit !== null) {
			return ['env:' . $explicit, self::sessionLabel($explicit)];
		}

		$method = self::server('REQUEST_METHOD');
		$requestUri = self::server('REQUEST_URI');

		if ($method !== null || $requestUri !== null) {
			$method = strtoupper($method ?? 'HTTP');
			$uri = $requestUri ?? self::server('SCRIPT_NAME') ?? self::server('PHP_SELF') ?? '/';
			$time = self::requestTime();
			$label = self::uriLabel($uri);
			$hash = self::hash([$time, $method, $uri, (string) getmypid()]);

			return ["http:{$time}:{$method}:{$uri}", "{$time}--{$method}--{$label}--{$hash}"];
		}

		$time = self::requestTime();
		$hash = self::hash([$time, (string) getmypid(), self::argv()]);

		return ["cli:{$time}", "{$time}--cli--{$hash}"];
	}

	private static function requestTime(): string
	{
		$time = self::server('REQUEST_TIME_FLOAT');

		if ($time !== null && is_numeric($time)) {
			$date = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', (float) $time));

			if ($date instanceof DateTimeImmutable) {
				return $date->format('Ymd-His-u');
			}
		}

		$time = self::server('REQUEST_TIME');

		if ($time !== null && ctype_digit($time)) {
			return new DateTimeImmutable('@' . $time)->format('Ymd-His') . '-000000';
		}

		return self::$fallbackTime ??= new DateTimeImmutable()->format('Ymd-His-u');
	}

	private static function argv(): string
	{
		return implode(' ', $_SERVER['argv'] ?? []);
	}

	private static function sessionLabel(string $value): string
	{
		$label = self::safeSegment($value);

		return strlen($label) <= 96 ? $label : substr($label, 0, 96);
	}

	private static function uriLabel(string $uri): string
	{
		$path = parse_url($uri, PHP_URL_PATH);
		$path = is_string($path) && $path !== '' ? $path : '/';
		$label = trim($path, '/');

		if ($label === '') {
			return 'root';
		}

		$label = preg_replace('/[^A-Za-z0-9._:-]+/', '-', $label) ?? 'request';
		$label = trim($label, '-.');
		$label = $label !== '' ? $label : 'request';

		return strlen($label) <= 64 ? $label : substr($label, 0, 64);
	}

	/** @param list<string> $parts */
	private static function hash(array $parts): string
	{
		return substr(hash('xxh128', implode("\0", $parts)), 0, 8);
	}

	private static function server(string $name): ?string
	{
		$value = $_SERVER[$name] ?? null;

		if (is_float($value) || is_int($value)) {
			return (string) $value;
		}

		return is_string($value) && $value !== '' ? $value : null;
	}

	private static function counter(): string
	{
		self::$counter++;

		return str_pad((string) self::$counter, 4, '0', STR_PAD_LEFT);
	}

	private static function safeRelativePath(string $path): string
	{
		$parts = preg_split('/[\\/]+/', $path, -1, PREG_SPLIT_NO_EMPTY);

		if (!is_array($parts) || count($parts) === 0) {
			return 'query.sql'; // @codeCoverageIgnore
		}

		$result = [];

		foreach ($parts as $part) {
			if ($part === '.' || $part === '..') {
				continue; // @codeCoverageIgnore
			}

			$result[] = self::safeSegment($part);
		}

		$path = implode(DIRECTORY_SEPARATOR, $result);

		return $path !== '' ? $path : 'query.sql';
	}

	private static function safeSegment(string $segment): string
	{
		$result = preg_replace('/[^A-Za-z0-9._:-]+/', '_', $segment);
		$result = trim($result ?? '', '.');

		return $result === '' ? '_' : $result;
	}
}
