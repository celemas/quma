<?php

declare(strict_types=1);

namespace Celema\Quma\Tests;

use Celema\Quma\Delimiters;
use Celema\Quma\Placeholders;
use InvalidArgumentException;
use RuntimeException;

/**
 * @internal
 */
class PlaceholdersTest extends TestCase
{
	public function testResolvesAllAndDriverPlaceholders(): void
	{
		$placeholders = $this->placeholders([
			'all' => [
				'prefix' => 'cms_',
				'table' => 'fallback',
			],
			'sqlite' => [
				'table' => 'members',
			],
		]);

		$this->assertSame(
			'SELECT * FROM cms_members',
			$placeholders->compileSql('SELECT * FROM [::prefix::][::table::]', 'query.sql'),
		);
	}

	public function testAllowsPlaceholderNamesWithSeparators(): void
	{
		$placeholders = $this->placeholders([
			'all' => [
				'schema.name' => 'main.',
				'cms:prefix' => 'cms_',
				'tenant-prefix' => 'tenant_',
			],
		]);

		$this->assertSame(
			'main.cms_tenant_nodes',
			$placeholders->compileSql(
				'[::schema.name::][::cms:prefix::][::tenant-prefix::]nodes',
				'query.sql',
			),
		);
	}

	public function testDelimiterFactories(): void
	{
		$this->assertSame('/*:name:*/', Delimiters::comments()->token('name'));
		$this->assertSame('[::name::]', Delimiters::brackets()->token('name'));
		$this->assertSame(
			['open' => '[[', 'close' => ']]'],
			new Delimiters('[[', ']]')->values(),
		);
	}

	public function testUsesCommentDelimiters(): void
	{
		$placeholders = $this->placeholders(
			['all' => ['table' => 'members']],
			Delimiters::comments(),
		);

		$this->assertSame(
			'SELECT * FROM members',
			$placeholders->compileSql('SELECT * FROM /*:table:*/', 'query.sql'),
		);
	}

	public function testUsesCustomDelimiters(): void
	{
		$placeholders = $this->placeholders(
			['all' => ['table' => 'members']],
			new Delimiters('[[', ']]'),
		);

		$this->assertSame(
			'SELECT * FROM members',
			$placeholders->compileSql('SELECT * FROM [[table]]', 'query.sql'),
		);
	}

	public function testCustomDelimiterErrorsUseConfiguredSyntax(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Malformed static placeholder in query.sql:1:15');
		$this->expectExceptionMessage('Expected [[name]]');
		$this->expectExceptionMessage('[[tenant-prefix]]');

		$placeholders = $this->placeholders([], new Delimiters('[[', ']]'));
		$placeholders->compileSql('SELECT * FROM [[table name]]', 'query.sql');
	}

	public function testUnknownPlaceholderThrowsHelpfulException(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage(
			'Unknown static placeholder [::table::] in query.sql:1:15 for driver "sqlite"',
		);
		$this->expectExceptionMessage(
			"Add placeholders['all']['table'] or placeholders['sqlite']['table']",
		);

		$placeholders = $this->placeholders();
		$placeholders->compileSql('SELECT * FROM [::table::]', 'query.sql');
	}

	public function testMalformedPlaceholderThrowsHelpfulException(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Malformed static placeholder in query.sql:1:15');
		$this->expectExceptionMessage('Expected [::name::]');

		$placeholders = $this->placeholders();
		$placeholders->compileSql('SELECT * FROM [::table name::]', 'query.sql');
	}

	public function testPlaceholderExceptionReportsMultilineLocation(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Unknown static placeholder [::table::] in query.sql:2:6');

		$placeholders = $this->placeholders();
		$placeholders->compileSql("SELECT 1\nFROM [::table::]", 'query.sql');
	}

	public function testDefaultScopeIsRejected(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("Replace placeholders['default'] with placeholders['all']");

		$this->placeholders([
			'default' => ['prefix' => 'cms_'],
		]);
	}

	public function testEmptyOpeningDelimiterIsRejected(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('opening delimiter must not be empty');

		new Delimiters('', ']]');
	}

	public function testNulClosingDelimiterIsRejected(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('closing delimiter must not contain NUL bytes');

		new Delimiters('[[', "]]\0]");
	}

	public function testEmptyScopeIsRejected(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Static placeholder scopes must be non-empty strings');

		$this->placeholders([
			'' => ['prefix' => 'cms_'],
		]);
	}

	public function testNumericScopeIsRejected(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Static placeholder scopes must be non-empty strings');

		$this->placeholders([
			['prefix' => 'cms_'],
		]);
	}

	public function testFlatMapIsRejected(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(
			"Static placeholders for scope 'prefix' must be an array of string values",
		);

		$this->placeholders([
			'prefix' => 'cms_',
		]);
	}

	public function testInvalidPlaceholderNameIsRejected(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid static placeholder name');

		$this->placeholders([
			'all' => ['table name' => 'members'],
		]);
	}

	public function testNonStringValueIsRejected(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("Static placeholder 'prefix' in scope 'all' must be a string");

		$this->placeholders([
			'all' => ['prefix' => 123],
		]);
	}

	public function testNestedPlaceholderValueIsRejected(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('must not contain another static placeholder');

		$this->placeholders([
			'all' => ['prefix' => '[::schema::].'],
		]);
	}

	/** @param array<array-key, mixed> $config */
	private function placeholders(array $config = [], ?Delimiters $delimiters = null): Placeholders
	{
		return new Placeholders('sqlite', $config, $delimiters ?? Delimiters::brackets());
	}
}
