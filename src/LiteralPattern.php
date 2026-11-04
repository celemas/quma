<?php

declare(strict_types=1);

namespace Celema\Quma;

/**
 * Alternatives share one pass, so a quote in a comment or a comment marker in a
 * string starts no match. PCRE's backtrack limit would otherwise apply from about 1 MB.
 *
 * @internal
 */
final class LiteralPattern
{
	private const string BLOCK_COMMENT = '\/\*(?:[^*]++|\*(?!\/))*+\*\/';
	private const string COMMENTS = self::BLOCK_COMMENT . '|--[^\n]*';
	private const string BLOCK = '\$\$(?:[^$]++|\$(?!\$))*+\$\$';
	private const string STRINGS = '\'[^\']*\'|"[^"]*"';
	private const string BACKTICKS = '`[^`]*`';

	private const string DEFAULT = '/' . self::BLOCK . '|' . self::COMMENTS . '|' . self::STRINGS . '/';

	// Backslashes escape in both quote styles, and -- only starts a comment before whitespace.
	private const string MYSQL =
		'/'
			. self::BLOCK_COMMENT
			. '|--(?=\s|\z)[^\n]*'
			. '|#[^\n]*'
			. '|\'(?:[^\'\\\\]++|\\\\[\s\S]|\'\')*+\''
			. '|"(?:[^"\\\\]++|\\\\[\s\S]|"")*+"'
			. '|'
			. self::BACKTICKS
			. '/';

	private const string SQLITE =
		'/'
			. self::COMMENTS
			. '|'
			. self::STRINGS
			. '|'
			. self::BACKTICKS
			. '|\[[^\]]*\]'
			. '/';

	// foo$a$ and type'a' hold no tag or E prefix, and backslashes escape only in E'' strings.
	private const string PGSQL =
		'/'
			. '(?<![A-Za-z0-9_$\x80-\xff])\$((?:[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)?)\$(?:[^$]++|\$(?!\1\$))*+\$\1\$'
			. '|'
			. self::COMMENTS
			. '|(?<![A-Za-z0-9_$\x80-\xff])[eE]\'(?:[^\'\\\\]++|\\\\[\s\S]|\'\')*+\''
			. '|'
			. self::STRINGS
			. '/';

	/** @return non-empty-string */
	public static function for(string $driver): string
	{
		return match ($driver) {
			'mysql' => self::MYSQL,
			'pgsql' => self::PGSQL,
			'sqlite' => self::SQLITE,
			default => self::DEFAULT,
		};
	}
}
