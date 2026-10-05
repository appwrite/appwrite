<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php74\Rector\Closure\ClosureToArrowFunctionRector;
use Rector\Php80\Rector\Catch_\RemoveUnusedVariableInCatchRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php80\Rector\NotIdentical\StrContainsRector;
use Rector\TypeDeclaration\Rector\ArrowFunction\AddArrowFunctionReturnTypeRector;
use Rector\TypeDeclaration\Rector\ClassMethod\PrivateMethodReturnTypeFromStrictNewArrayRector;
use Rector\TypeDeclaration\Rector\Closure\ClosureReturnTypeRector;
use Rector\TypeDeclaration\Rector\FuncCall\AddArrayFunctionClosureParamTypeRector;
use Rector\TypeDeclaration\Rector\Property\TypedPropertyFromAssignsRector;
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
        __DIR__ . '/tests/E2E/Resources',
        AddArrayFunctionClosureParamTypeRector::class => [__DIR__ . '/src'],
        AddArrowFunctionReturnTypeRector::class => [__DIR__ . '/src'],
        ClassPropertyAssignToConstructorPromotionRector::class => [__DIR__ . '/src'],
        ClosureReturnTypeRector::class => [__DIR__ . '/src'],
        ClosureToArrowFunctionRector::class => [__DIR__ . '/src'],
        PrivateMethodReturnTypeFromStrictNewArrayRector::class => [__DIR__ . '/src'],
        RemoveUnusedVariableInCatchRector::class => [__DIR__ . '/src'],
        StrContainsRector::class => [__DIR__ . '/src'],
        TypedPropertyFromAssignsRector::class => [__DIR__ . '/src'],
        TypedPropertyFromStrictConstructorRector::class => [__DIR__ . '/src'],
    ]);
