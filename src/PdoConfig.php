<?php

declare(strict_types=1);

namespace Celemas\Quma;

use PDO;

/** @api */
final class PdoConfig
{
	private const array DEFAULT_OPTIONS = [
		PDO::ATTR_CURSOR => PDO::CURSOR_SCROLL,
		PDO::ATTR_EMULATE_PREPARES => true,
		PDO::ATTR_CASE => PDO::CASE_NATURAL,
	];
	private const array REQUIRED_OPTIONS = [
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
	];

	/** @param array<array-key, mixed> $options */
	public function __construct(
		public readonly ?string $username = null,
		#[\SensitiveParameter]
		public readonly ?string $password = null,
		public readonly array $options = [],
		public readonly int $fetchMode = PDO::FETCH_ASSOC,
	) {}

	public function credentials(
		string $username,
		#[\SensitiveParameter]
		?string $password = null,
	): self {
		return new self($username, $password, $this->options, $this->fetchMode);
	}

	/** @param array<array-key, mixed> $options */
	public function options(array $options): self
	{
		return new self($this->username, $this->password, $options, $this->fetchMode);
	}

	public function option(int $attribute, mixed $value): self
	{
		/** @var array<array-key, mixed> $options */
		$options = array_replace($this->options, [$attribute => $value]);

		return new self($this->username, $this->password, $options, $this->fetchMode);
	}

	public function fetch(int $fetchMode): self
	{
		return new self($this->username, $this->password, $this->options, $fetchMode);
	}

	/** @return array<array-key, mixed> */
	public function effectiveOptions(): array
	{
		return array_replace(self::DEFAULT_OPTIONS, $this->options, self::REQUIRED_OPTIONS);
	}
}
