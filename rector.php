<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\PHPUnit\Set\PHPUnitSetList;

return RectorConfig::configure()
    ->withBootstrapFiles([
        __DIR__ . '/app/init/constants.php',
    ])
    ->withPaths([
        __DIR__ . '/tests',
    ])
    ->withSkipPath(__DIR__ . '/vendor')
    ->withSkipPath(__DIR__ . '/tests/resources')
    ->withSets([
        PHPUnitSetList::PHPUNIT_CODE_QUALITY,
    ]);
