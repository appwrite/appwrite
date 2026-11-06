<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php74\Rector\Closure\ClosureToArrowFunctionRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\TypeDeclaration\Rector\ArrowFunction\AddArrowFunctionReturnTypeRector;
use Rector\TypeDeclaration\Rector\Closure\ClosureReturnTypeRector;
use Rector\TypeDeclaration\Rector\FuncCall\AddArrayFunctionClosureParamTypeRector;
use Rector\TypeDeclaration\Rector\FuncCall\AddArrowFunctionParamArrayWhereDimFetchRector;
use Rector\TypeDeclaration\Rector\FunctionLike\AddClosureParamTypeForArrayMapRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpSets()
    ->withPreparedSets(
        typeDeclarations: true,
    )
    // Absorbing moves code: keep the public surface (property declarations,
    // readonly-ness) and the closures exactly as released.
    ->withSkip([
        AddArrayFunctionClosureParamTypeRector::class,
        AddArrowFunctionParamArrayWhereDimFetchRector::class,
        AddArrowFunctionReturnTypeRector::class,
        AddClosureParamTypeForArrayMapRector::class,
        ClassPropertyAssignToConstructorPromotionRector::class,
        ClosureReturnTypeRector::class,
        ClosureToArrowFunctionRector::class,
        ReadOnlyPropertyRector::class,
    ]);
