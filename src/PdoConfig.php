<?php

declare(strict_types=1);

namespace Celema\Quma;

use Closure;
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
	/** For SQLite, PDO::ATTR_TIMEOUT limits waiting for a locked database instead of connecting. */
	private const array CONNECT_TIMEOUT_DRIVERS = ['mysql', 'pgsql'];
	private const int CONNECT_TIMEOUT = 5;

	/**
	 * @param array<array-key, mixed> $options
	 * @param (Closure(): array{string, ?string})|null $credentialResolver
	 */
	public function __construct(
		public readonly ?string $username = null,
		#[\SensitiveParameter]
		public readonly ?string $password = null,
		public readonly array $options = [],
		public readonly int $fetchMode = PDO::FETCH_ASSOC,
		public readonly ?Closure $credentialResolver = null,
	) {}

	public function credentials(
		string $username,
		#[\SensitiveParameter]
		?string $password = null,
	): self {
		return new self($username, $password, $this->options, $this->fetchMode);
	}

	/** @param Closure(): array{string, ?string} $resolver */
	public function credentialsFrom(Closure $resolver): self
	{
		return new self(null, null, $this->options, $this->fetchMode, $resolver);
	}

	/** @param array<array-key, mixed> $options */
	public function options(array $options): self
	{
		return new self($this->username, $this->password, $options, $this->fetchMode, $this->credentialResolver);
	}

	public function option(int $attribute, mixed $value): self
	{
		$options = array_replace($this->options, [$attribute => $value]);

		return new self($this->username, $this->password, $options, $this->fetchMode, $this->credentialResolver);
	}

	public function fetch(int $fetchMode): self
	{
		return new self($this->username, $this->password, $this->options, $fetchMode, $this->credentialResolver);
	}

	/**
	 * The username and password for a new connection, from the resolver if
	 * one is set.
	 *
	 * @return array{?string, ?string}
	 */
	public function resolveCredentials(): array
	{
		if ($this->credentialResolver === null) {
			return [$this->username, $this->password];
		}

		return ($this->credentialResolver)();
	}

	/** @return array<array-key, mixed> */
	public function effectiveOptions(string $driver): array
	{
		$defaults = self::DEFAULT_OPTIONS;

		if (in_array($driver, self::CONNECT_TIMEOUT_DRIVERS, strict: true)) {
			$defaults[PDO::ATTR_TIMEOUT] = self::CONNECT_TIMEOUT;
		}

		return array_replace($defaults, $this->options, self::REQUIRED_OPTIONS);
	}
}
