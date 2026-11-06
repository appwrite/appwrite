<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Catch_\RemoveUnusedVariableInCatchRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php80\Rector\NotIdentical\StrContainsRector;
use Rector\Php80\Rector\Switch_\ChangeSwitchToMatchRector;
use Rector\Php83\Rector\ClassConst\AddTypeToConstRector;
use Rector\TypeDeclaration\Rector\Property\TypedPropertyFromStrictConstructorRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpSets()
    ->withPreparedSets(
        typeDeclarations: true,
    )
    // Absorbing moves code: keep src exactly as released.
    ->withSkip([
        AddTypeToConstRector::class => [__DIR__ . '/src'],
        ChangeSwitchToMatchRector::class => [__DIR__ . '/src'],
        ClassPropertyAssignToConstructorPromotionRector::class => [__DIR__ . '/src'],
        RemoveUnusedVariableInCatchRector::class => [__DIR__ . '/src'],
        StrContainsRector::class => [__DIR__ . '/src'],
        TypedPropertyFromStrictConstructorRector::class => [__DIR__ . '/src'],
    ]);
