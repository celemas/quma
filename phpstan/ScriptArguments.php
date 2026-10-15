<?php

declare(strict_types=1);

namespace Celema\Quma\PHPStan;

use Override;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\PassedByReference;
use PHPStan\Type\MixedType;
use PHPStan\Type\Type;

final class ScriptArguments implements ParameterReflection
{
	#[Override]
	public function getName(): string
	{
		return 'args';
	}

	#[Override]
	public function isOptional(): bool
	{
		return true;
	}

	#[Override]
	public function getType(): Type
	{
		return new MixedType();
	}

	#[Override]
	public function passedByReference(): PassedByReference
	{
		return PassedByReference::createNo();
	}

	#[Override]
	public function isVariadic(): bool
	{
		return true;
	}

	#[Override]
	public function getDefaultValue(): ?Type
	{
		return null;
	}
}
