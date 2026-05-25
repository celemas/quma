<?php

declare(strict_types=1);

namespace Celemas\Quma;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** @api */
class Script
{
	private const array RESERVED_TEMPLATE_PARAMETERS = [
		'pdodriver' => true,
	];

	protected Database $db;
	protected string $script;
	protected bool $isTemplate;
	protected string $sourcePath;
	/** @var (Closure(string, string): string)|null */
	protected ?Closure $compile;

	public function __construct(
		Database $db,
		LoadedScript $script,
		bool $isTemplate,
	) {
		$this->db = $db;
		$this->script = $script->source;
		$this->isTemplate = $isTemplate;
		$this->sourcePath = $script->sourcePath;
		$this->compile = $script->compile;
	}

	public function __invoke(mixed ...$args): Query
	{
		return $this->invoke(...$args);
	}

	public function invoke(mixed ...$argsArray): Query
	{
		$args = new Args($argsArray);

		if ($this->isTemplate) {
			if ($args->type() === ArgType::Positional) {
				throw new InvalidArgumentException(
					'Template queries `*.tpql` allow named parameters only',
				);
			}

			$script = $this->evaluateTemplate($this->script, $args);

			if ($this->compile !== null) {
				$script = ($this->compile)($script, $this->sourcePath);
			}

			// We need to wrap the result of the prepare call in an array
			// to get back to the format of ...$argsArray.
			$args = new Args([$this->prepareTemplateVars($script, $args)]);
		} else {
			$script = $this->script;
		}

		return new Query($this->db, $script, $args, $this->sourcePath);
	}

	protected function evaluateTemplate(string $template, Args $args): string
	{
		$context = $this->buildTemplateContext($args);

		if ($template === $this->sourcePath) {
			if (!is_file($this->sourcePath)) {
				return '';
			}

			return $this->renderTemplateFile($this->sourcePath, $context);
		}

		return $this->renderTemplateSource($template, $context);
	}

	/**
	 * @return array<array-key, mixed>
	 */
	protected function buildTemplateContext(Args $args): array
	{
		$named = $args->getNamed();

		foreach (array_keys(self::RESERVED_TEMPLATE_PARAMETERS) as $name) {
			if (array_key_exists($name, $named)) {
				throw new InvalidArgumentException("Template parameter '{$name}' is reserved.");
			}
		}

		return array_merge(
			$named,
			['pdodriver' => $this->db->getPdoDriver()],
		);
	}

	/**
	 * @param string $templatePath
	 * @param array<array-key, mixed> $context
	 */
	protected function renderTemplateFile(string $templatePath, array $context): string
	{
		ob_start();

		try {
			(static function (string $__templatePath, array $__context): void {
				extract($__context, EXTR_SKIP);

				/** @psalm-suppress UnresolvableInclude */
				require $__templatePath;
			})($templatePath, $context);

			$result = ob_get_clean();

			return is_string($result) ? $result : '';
		} catch (Throwable $e) {
			ob_end_clean();

			throw $e;
		}
	}

	/**
	 * @param array<array-key, mixed> $context
	 */
	protected function renderTemplateSource(string $template, array $context): string
	{
		$templatePath = tempnam(sys_get_temp_dir(), 'quma-tpql-');

		if ($templatePath === false) {
			// tempnam() failure depends on system temp-dir failure and is not usefully reproducible.
			throw new RuntimeException('Could not create temporary template file'); // @codeCoverageIgnore
		}

		try {
			if (file_put_contents($templatePath, $template) === false) {
				// This would require making the just-created temp file unwritable between calls.
				throw new RuntimeException('Could not write temporary template file'); // @codeCoverageIgnore
			}

			return $this->renderTemplateFile($templatePath, $context);
		} finally {
			if (is_file($templatePath)) {
				unlink($templatePath);
			}
		}
	}

	/**
	 * Removes all keys from $params which are not present
	 * in the $script.
	 *
	 * PDO does not allow unused parameters.
	 */
	protected function prepareTemplateVars(string $script, Args $args): array
	{
		// Remove PostgreSQL blocks
		$cleaned = preg_replace(Query::PATTERN_BLOCK, ' ', $script);
		// Remove strings
		$cleaned = preg_replace(Query::PATTERN_STRING, ' ', $cleaned ?? '');
		// Remove /* */ comments
		$cleaned = preg_replace(Query::PATTERN_COMMENT_MULTI, ' ', $cleaned ?? '');
		// Remove single line comments
		$cleaned = preg_replace(Query::PATTERN_COMMENT_SINGLE, ' ', $cleaned ?? '');

		$newArgs = [];

		// Match everything starting with : and a letter.
		// Exclude multiple colons, like type casts (::text).
		// Would not find a var if it is at the very beginning of script.
		$matches = preg_match_all(
			'/[^:]:[a-zA-Z][a-zA-Z0-9_]*/',
			$cleaned ?? '',
			$result,
			PREG_PATTERN_ORDER,
		);

		if ($matches !== false && $matches > 0) {
			$argsArray = $args->getNamed();
			$namedKeys = [];
			$newArgs = [];

			foreach (array_unique($result[0]) as $arg) {
				$a = substr($arg, 2);

				if ($a !== '') {
					$namedKeys[$a] = true;
				}
			}

			if (count($namedKeys) > 0) {
				$newArgs = array_intersect_key($argsArray, $namedKeys);
			}
		}

		return $newArgs;
	}
}
