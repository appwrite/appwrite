<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\PHPUnit\Set\PHPUnitSetList;

$root = dirname(__DIR__, 2);

return RectorConfig::configure()
    ->withBootstrapFiles([
        $root . '/app/init/constants.php',
    ])
    ->withPaths([
        $root . '/tests',
    ])
    ->withSkipPath($root . '/vendor')
    ->withSkipPath($root . '/tests/resources')
    ->withSkipPath($root . '/tests/tools')
    ->withSets([
        PHPUnitSetList::PHPUNIT_CODE_QUALITY,
    ]);
