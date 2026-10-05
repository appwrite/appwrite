<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php74\Rector\Assign\NullCoalescingOperatorRector;
use Rector\Php74\Rector\Closure\ClosureToArrowFunctionRector;
use Rector\Php80\Rector\Catch_\RemoveUnusedVariableInCatchRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\TypeDeclaration\Rector\ArrowFunction\AddArrowFunctionReturnTypeRector;
use Rector\TypeDeclaration\Rector\Closure\AddClosureVoidReturnTypeWhereNoReturnRector;
use Rector\TypeDeclaration\Rector\Closure\ClosureReturnTypeRector;
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
        // Rector's PHP 8.4 printer drops the parentheses in `(new \DateTime())->`
        // whenever it reprints these files.
        __DIR__ . '/src/Adapters/TimeLimit/Appwrite/TablesDB.php',
        __DIR__ . '/src/Adapters/TimeLimit/Database.php',
        AddArrayFunctionClosureParamTypeRector::class => [__DIR__ . '/src'],
        AddArrowFunctionReturnTypeRector::class => [__DIR__ . '/src'],
        AddClosureVoidReturnTypeWhereNoReturnRector::class => [__DIR__ . '/src'],
        ClassPropertyAssignToConstructorPromotionRector::class => [__DIR__ . '/src'],
        ClosureReturnTypeRector::class => [__DIR__ . '/src'],
        ClosureToArrowFunctionRector::class => [__DIR__ . '/src'],
        NullCoalescingOperatorRector::class => [__DIR__ . '/src'],
        RemoveUnusedVariableInCatchRector::class => [__DIR__ . '/src'],
    ]);
