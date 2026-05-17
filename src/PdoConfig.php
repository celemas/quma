<?php

declare(strict_types=1);

namespace Celemas\Quma;

use PDO;

/** @api */
final class PdoConfig
{
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
}
