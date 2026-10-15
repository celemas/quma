<?php

declare(strict_types=1);

namespace Celema\Quma\PHPStan;

use Celema\Quma\Folder;
use Override;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;

final class FolderMethods implements MethodsClassReflectionExtension
{
	#[Override]
	public function hasMethod(ClassReflection $classReflection, string $methodName): bool
	{
		// Folder::__call() resolves any name to a script file at runtime.
		return $classReflection->is(Folder::class);
	}

	#[Override]
	public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
	{
		return new ScriptMethod($classReflection, $methodName);
	}
}
