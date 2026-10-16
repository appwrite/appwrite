<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        earlyReturn: true,
        phpunitCodeQuality: true,
    )
    // Absorbing moves code: the released source and its suites stay exactly
    // as they shipped until the phase 8 burn-down (rfc/monorepo.md).
    ->withSkip([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ]);
