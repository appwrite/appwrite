<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php74\Rector\Property\RestoreDefaultNullToNullableTypePropertyRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpSets()
    ->withPreparedSets(
        typeDeclarations: true,
    )
    ->withSkip([
        // The optional-key fixtures declare nullable properties without a default on purpose
        RestoreDefaultNullToNullableTypePropertyRector::class => [__DIR__ . '/tests/ConfigTest.php'],
    ]);
