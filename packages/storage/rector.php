<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\FunctionLike\RemoveDeadReturnRector;
use Rector\Php74\Rector\If_\IfToNullCoalescingAssignRector;
use Rector\TypeDeclaration\Rector\ClassMethod\AddVoidReturnTypeWhereNoReturnRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        typeDeclarationDocblocks: true,
        privatization: true,
        instanceOf: true,
        earlyReturn: true,
        phpunitCodeQuality: true,
        rectorPreset: true,
    )
    // Absorbing moves code: keep the tests exactly as released.
    ->withSkip([
        AddVoidReturnTypeWhereNoReturnRector::class,
        IfToNullCoalescingAssignRector::class,
        RemoveDeadReturnRector::class,
    ]);
