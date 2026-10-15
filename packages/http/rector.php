<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\Php70\Rector\FuncCall\RandomFunctionRector;
use Rector\Php74\Rector\If_\IfToNullCoalescingAssignRector;
use Rector\Php74\Rector\Property\RestoreDefaultNullToNullableTypePropertyRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Php84\Rector\MethodCall\NewMethodCallWithoutParenthesesRector;
use Rector\Php85\Rector\ArrayDimFetch\ArrayFirstLastRector;
use Rector\TypeDeclaration\Rector\ArrowFunction\AddArrowFunctionReturnTypeRector;
use Rector\TypeDeclaration\Rector\ClassMethod\ParamTypeByParentCallTypeRector;
use Rector\TypeDeclaration\Rector\Closure\AddClosureNeverReturnTypeRector;
use Rector\TypeDeclaration\Rector\Closure\AddClosureVoidReturnTypeWhereNoReturnRector;
use Rector\TypeDeclaration\Rector\Closure\ClosureReturnTypeRector;
use Rector\TypeDeclaration\Rector\FuncCall\AddArrayFunctionClosureParamTypeRector;
use Rector\TypeDeclaration\Rector\FuncCall\AddArrowFunctionParamArrayWhereDimFetchRector;
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
    // Absorbing moves code: keep the source exactly as released.
    ->withSkip([
        __DIR__ . '/tests/bench',
        AddArrayFunctionClosureParamTypeRector::class,
        AddArrowFunctionParamArrayWhereDimFetchRector::class,
        AddArrowFunctionReturnTypeRector::class,
        AddClosureNeverReturnTypeRector::class,
        AddClosureVoidReturnTypeWhereNoReturnRector::class,
        ArrayFirstLastRector::class,
        ClassPropertyAssignToConstructorPromotionRector::class,
        ClosureReturnTypeRector::class,
        IfToNullCoalescingAssignRector::class,
        NewMethodCallWithoutParenthesesRector::class,
        ParamTypeByParentCallTypeRector::class,
        RandomFunctionRector::class,
        ReadOnlyPropertyRector::class,
        RestoreDefaultNullToNullableTypePropertyRector::class,
        StringClassNameToClassConstantRector::class,
        TypedPropertyFromAssignsRector::class,
        TypedPropertyFromStrictConstructorRector::class,
    ]);
