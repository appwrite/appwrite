<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withParallel(timeoutSeconds: 600)
    ->withImportNames(importShortClasses: false)
    ->withPhpSets()
    ->withPreparedSets(
        typeDeclarations: true,
    );
