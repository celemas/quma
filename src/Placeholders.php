<?php

declare(strict_types=1);

namespace Celema\Quma;

use InvalidArgumentException;
use RuntimeException;

final class Placeholders
{
	public const string NAME_PATTERN = '[A-Za-z_][A-Za-z0-9_.:-]*';

	/** @var non-empty-string */
	private readonly string $tokenPattern;

	/** @var non-empty-string */
	private readonly string $tokenStartPattern;

	/** @var array<string, string> */
	private readonly array $values;

	/** @param array<array-key, mixed> $config */
	public function __construct(
		private readonly string $driver,
		array $config,
		private readonly Delimiters $delimiters,
	) {
		$open = preg_quote($this->delimiters->open, '/');
		$close = preg_quote($this->delimiters->close, '/');
		$this->tokenPattern = '/' . $open . '(' . self::NAME_PATTERN . ')' . $close . '/';
		$this->tokenStartPattern = '/^' . $open . '(' . self::NAME_PATTERN . ')' . $close . '/';

		$normalized = $this->normalizeConfig($config);
		$this->values = array_replace(
			$normalized['all'] ?? [],
			$normalized[$this->driver] ?? [],
		);
	}

	public function compileSql(string $source, string $path): string
	{
		return $this->substituteFragment($source, $path, $source, 0);
	}

	/**
	 * @param array<array-key, mixed> $config
	 *
	 * @return array<string, array<string, string>>
	 */
	private function normalizeConfig(array $config): array
	{
		$normalized = [];

		foreach ($config as $scope => $values) {
			if (!is_string($scope) || $scope === '') {
				throw new InvalidArgumentException(
					'Static placeholder scopes must be non-empty strings.',
				);
			}

			if ($scope === 'default') {
				throw new InvalidArgumentException(
					"Static placeholders use the shared scope 'all'. Replace placeholders['default'] with placeholders['all'].",
				);
			}

			if (!is_array($values)) {
				throw new InvalidArgumentException(
					"Static placeholders for scope '{$scope}' must be an array of string values.",
				);
			}

			$normalized[$scope] = $this->normalizeValues($scope, $values);
		}

		return $normalized;
	}

	/**
	 * @param array<array-key, mixed> $values
	 *
	 * @return array<string, string>
	 */
	private function normalizeValues(string $scope, array $values): array
	{
		$normalized = [];

		foreach ($values as $name => $value) {
			if (!is_string($name) || !preg_match('/^' . self::NAME_PATTERN . '$/', $name)) {
				throw new InvalidArgumentException(
					"Invalid static placeholder name in scope '{$scope}'. Names must match "
					. self::NAME_PATTERN
					. '.',
				);
			}

			if (!is_string($value)) {
				throw new InvalidArgumentException(
					"Static placeholder '{$name}' in scope '{$scope}' must be a string.",
				);
			}

			if (str_contains($value, $this->delimiters->open)) {
				throw new InvalidArgumentException(
					"Static placeholder '{$name}' in scope '{$scope}' must not contain another static placeholder.",
				);
			}

			$normalized[$name] = $value;
		}

		return $normalized;
	}

	private function substituteFragment(
		string $fragment,
		string $path,
		string $source,
		int $baseOffset,
	): string {
		$this->assertNoMalformedTokens($fragment, $path, $source, $baseOffset);

		$matches = [];
		$result = preg_match_all(
			$this->tokenPattern,
			$fragment,
			$matches,
			PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
		);

		if ($result === false || $result === 0) {
			return $fragment;
		}

		$compiled = '';
		$cursor = 0;

		foreach ($matches as $match) {
			$placeholder = $match[0][0];
			$offset = $match[0][1];
			$name = $match[1][0];

			$compiled .= substr($fragment, $cursor, $offset - $cursor);

			if (!array_key_exists($name, $this->values)) {
				throw $this->unknownPlaceholder($placeholder, $name, $path, $source, $baseOffset + $offset);
			}

			$compiled .= $this->values[$name];
			$cursor = $offset + strlen($placeholder);
		}

		return $compiled . substr($fragment, $cursor);
	}

	private function assertNoMalformedTokens(
		string $fragment,
		string $path,
		string $source,
		int $baseOffset,
	): void {
		$offset = 0;

		while (($position = strpos($fragment, $this->delimiters->open, $offset)) !== false) {
			$matches = [];
			$tail = substr($fragment, $position);

			if (preg_match($this->tokenStartPattern, $tail, $matches) !== 1) {
				throw $this->malformedPlaceholder($path, $source, $baseOffset + $position);
			}

			$offset = $position + strlen($matches[0]);
		}
	}

	private function unknownPlaceholder(
		string $placeholder,
		string $name,
		string $path,
		string $source,
		int $offset,
	): RuntimeException {
		[$line, $column] = $this->location($source, $offset);

		return new RuntimeException(
			"Unknown static placeholder {$placeholder} in {$path}:{$line}:{$column} for driver \"{$this->driver}\".\n"
			. "No value was configured for \"{$name}\".\n"
			. "Add placeholders['all']['{$name}'] or placeholders['{$this->driver}']['{$name}'].\n"
			. 'Static placeholders are raw SQL fragments. Use them only for trusted configuration, never for user input.',
		);
	}

	private function malformedPlaceholder(string $path, string $source, int $offset): RuntimeException
	{
		[$line, $column] = $this->location($source, $offset);

		return new RuntimeException(
			"Malformed static placeholder in {$path}:{$line}:{$column}.\n"
			. 'Expected '
			. $this->delimiters->token('name')
			. ' where name matches: '
			. self::NAME_PATTERN
			. ".\n"
			. 'Examples: '
			. $this->delimiters->token('prefix')
			. ', '
			. $this->delimiters->token('schema.name')
			. ', '
			. $this->delimiters->token('tenant-prefix')
			. ', '
			. $this->delimiters->token('cms:prefix')
			. '.',
		);
	}

	/** @return array{0: int, 1: int} */
	private function location(string $source, int $offset): array
	{
		$before = substr($source, 0, $offset);
		$line = substr_count($before, "\n") + 1;
		$lineStart = strrpos($before, "\n");
		$column = $offset + 1;

		if ($lineStart !== false) {
			$column = $offset - $lineStart;
		}

		return [$line, $column];
	}
}
