<?php

declare(strict_types=1);

namespace Celema\Quma\PHPStan;

use Celema\Quma\Query;
use Override;
use PHPStan\Reflection\ClassMemberReflection;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\FunctionVariant;
use PHPStan\Reflection\MethodReflection;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

final class ScriptMethod implements MethodReflection
{
	public function __construct(
		private readonly ClassReflection $folder,
		private readonly string $name,
	) {}

	#[Override]
	public function getDeclaringClass(): ClassReflection
	{
		return $this->folder;
	}

	#[Override]
	public function isStatic(): bool
	{
		return false;
	}

	#[Override]
	public function isPrivate(): bool
	{
		return false;
	}

	#[Override]
	public function isPublic(): bool
	{
		return true;
	}

	#[Override]
	public function getDocComment(): ?string
	{
		return null;
	}

	#[Override]
	public function getName(): string
	{
		return $this->name;
	}

	#[Override]
	public function getPrototype(): ClassMemberReflection
	{
		return $this;
	}

	#[Override]
	public function getVariants(): array
	{
		return [
			new FunctionVariant(
				TemplateTypeMap::createEmpty(),
				null,
				[new ScriptArguments()],
				true,
				new ObjectType(Query::class),
			),
		];
	}

	#[Override]
	public function isDeprecated(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	#[Override]
	public function getDeprecatedDescription(): ?string
	{
		return null;
	}

	#[Override]
	public function isFinal(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	#[Override]
	public function isInternal(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	#[Override]
	public function getThrowType(): ?Type
	{
		return null;
	}

	#[Override]
	public function hasSideEffects(): TrinaryLogic
	{
		return TrinaryLogic::createYes();
	}
}
