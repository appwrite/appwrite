<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php70\Rector\StmtsAwareInterface\IfIssetToCoalescingRector;
use Rector\Php80\Rector\Catch_\RemoveUnusedVariableInCatchRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php80\Rector\NotIdentical\StrContainsRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Php83\Rector\ClassConst\AddTypeToConstRector;
use Rector\Php84\Rector\Foreach_\ForeachToArrayAnyRector;
use Rector\Php84\Rector\MethodCall\NewMethodCallWithoutParenthesesRector;
use Rector\TypeDeclaration\Rector\ArrowFunction\AddArrowFunctionReturnTypeRector;
use Rector\TypeDeclaration\Rector\Closure\AddClosureVoidReturnTypeWhereNoReturnRector;
use Rector\TypeDeclaration\Rector\Closure\ClosureReturnTypeRector;
use Rector\TypeDeclaration\Rector\FuncCall\AddArrayAnyAllClosureParamTypeRector;
use Rector\TypeDeclaration\Rector\FuncCall\AddArrayFunctionClosureParamTypeRector;

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
        AddArrayAnyAllClosureParamTypeRector::class => [__DIR__ . '/src'],
        AddArrayFunctionClosureParamTypeRector::class => [__DIR__ . '/src'],
        AddArrowFunctionReturnTypeRector::class => [__DIR__ . '/src'],
        AddClosureVoidReturnTypeWhereNoReturnRector::class => [__DIR__ . '/src'],
        AddTypeToConstRector::class => [__DIR__ . '/src'],
        ClassPropertyAssignToConstructorPromotionRector::class => [__DIR__ . '/src'],
        ClosureReturnTypeRector::class => [__DIR__ . '/src'],
        ForeachToArrayAnyRector::class => [__DIR__ . '/src'],
        IfIssetToCoalescingRector::class => [__DIR__ . '/src'],
        NewMethodCallWithoutParenthesesRector::class => [__DIR__ . '/src'],
        ReadOnlyPropertyRector::class => [__DIR__ . '/src'],
        RemoveUnusedVariableInCatchRector::class => [__DIR__ . '/src'],
        StrContainsRector::class => [__DIR__ . '/src'],
    ]);
