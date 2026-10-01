<?php

declare(strict_types=1);

namespace Utopia\Client\PHPStan;

use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Rules\RestrictedUsage\RestrictedFunctionUsageExtension;
use PHPStan\Rules\RestrictedUsage\RestrictedUsage;

/**
 * A curl handle opened anywhere but the Curl adapter bypasses the client's Destinations,
 * so nothing checks the address it reaches.
 */
class DisallowRawCurlExtension implements RestrictedFunctionUsageExtension
{
    private const array FUNCTIONS = ['curl_init', 'curl_multi_init'];

    public function isRestrictedFunctionUsage(FunctionReflection $functionReflection, Scope $scope): ?RestrictedUsage
    {
        if (!\in_array($functionReflection->getName(), self::FUNCTIONS, true)) {
            return null;
        }

        if (\str_starts_with($scope->getFile(), \realpath(__DIR__ . '/../Adapter/Curl') . '/')) {
            return null;
        }

        return RestrictedUsage::create(
            errorMessage: 'Send HTTP requests through Utopia\Client\Client, whose adapter checks every connection against its Destinations.',
            identifier: 'function.disallowedCurl',
        );
    }
}
