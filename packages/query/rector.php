<?php

declare(strict_types=1);

use Rector\CodingStyle\Rector\ArrowFunction\ArrowFunctionDelegatingCallToFirstClassCallableRector;
use Rector\Config\RectorConfig;
use Rector\Php70\Rector\Ternary\TernaryToNullCoalescingRector;
use Rector\Php74\Rector\If_\IfToNullCoalescingAssignRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php80\Rector\FuncCall\ClassOnObjectRector;
use Rector\Php83\Rector\ClassConst\AddTypeToConstRector;
use Rector\Php84\Rector\Foreach_\ForeachToArrayAnyRector;
use Rector\TypeDeclaration\Rector\ArrowFunction\AddArrowFunctionReturnTypeRector;
use Rector\TypeDeclaration\Rector\FuncCall\AddArrayAnyAllClosureParamTypeRector;
use Rector\TypeDeclaration\Rector\FuncCall\AddArrayFunctionClosureParamTypeRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    // Builder.php alone is ~100K; the default 120s per process is not enough.
    ->withParallel(timeoutSeconds: 600)
    ->withPhpSets()
    ->withPreparedSets(
        typeDeclarations: true,
    )
    // Absorbing moves code: keep the library source exactly as released.
    ->withSkip([
        AddArrayAnyAllClosureParamTypeRector::class => [__DIR__ . '/src'],
        AddArrayFunctionClosureParamTypeRector::class => [__DIR__ . '/src'],
        AddArrowFunctionReturnTypeRector::class => [__DIR__ . '/src'],
        AddTypeToConstRector::class => [__DIR__ . '/src'],
        ArrowFunctionDelegatingCallToFirstClassCallableRector::class => [__DIR__ . '/src'],
        ClassOnObjectRector::class => [__DIR__ . '/src'],
        ClassPropertyAssignToConstructorPromotionRector::class => [__DIR__ . '/src'],
        ForeachToArrayAnyRector::class => [__DIR__ . '/src'],
        IfToNullCoalescingAssignRector::class => [__DIR__ . '/src'],
        TernaryToNullCoalescingRector::class => [__DIR__ . '/src'],
    ]);
