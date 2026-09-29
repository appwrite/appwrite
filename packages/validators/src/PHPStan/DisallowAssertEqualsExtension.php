<?php

declare(strict_types=1);

namespace Utopia\PHPStan;

use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Rules\RestrictedUsage\RestrictedMethodUsageExtension;
use PHPStan\Rules\RestrictedUsage\RestrictedUsage;

class DisallowAssertEqualsExtension implements RestrictedMethodUsageExtension
{
    public function isRestrictedMethodUsage(
        ExtendedMethodReflection $methodReflection,
        Scope $scope,
    ): ?RestrictedUsage {
        if ($methodReflection->getName() !== 'assertEquals') {
            return null;
        }

        $declaringClass = $methodReflection->getDeclaringClass();
        if ($declaringClass->getName() !== \PHPUnit\Framework\Assert::class) {
            return null;
        }

        return RestrictedUsage::create(
            errorMessage: 'Use assertSame() instead of assertEquals()',
            identifier: 'method.disallowedAssertEquals',
        );
    }
}
