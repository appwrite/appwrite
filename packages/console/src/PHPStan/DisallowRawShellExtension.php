<?php

declare(strict_types=1);

namespace Utopia\Console\PHPStan;

use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Rules\RestrictedUsage\RestrictedFunctionUsageExtension;
use PHPStan\Rules\RestrictedUsage\RestrictedUsage;

/**
 * A command assembled as a string runs through /bin/sh, where a value nobody
 * escaped becomes code. Command builds it instead: Console::execute() hands a
 * plain one to the program as argv and escapes every argument of a composite
 * one, so nothing is left to escape by hand.
 */
class DisallowRawShellExtension implements RestrictedFunctionUsageExtension
{
    /**
     * Functions that hand a string to /bin/sh, or escape one for it.
     */
    private const array FUNCTIONS = ['exec', 'shell_exec', 'system', 'passthru', 'popen', 'escapeshellarg', 'escapeshellcmd'];

    public function isRestrictedFunctionUsage(FunctionReflection $functionReflection, Scope $scope): ?RestrictedUsage
    {
        if (!\in_array($functionReflection->getName(), self::FUNCTIONS, true)) {
            return null;
        }

        // Console and Command are where the shell is reached on everyone's behalf
        if (\str_starts_with($scope->getFile(), \dirname(__DIR__) . '/')) {
            return null;
        }

        return RestrictedUsage::create(
            errorMessage: $functionReflection->getName() . '() reaches a shell. Build the command with Utopia\Console\Command and run it with Console::execute().',
            identifier: 'function.disallowedShell',
        );
    }
}
