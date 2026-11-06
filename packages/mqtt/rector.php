<?php

declare(strict_types=1);

use Rector\CodingStyle\Rector\FuncCall\FunctionFirstClassCallableRector;
use Rector\Config\RectorConfig;
use Rector\Php74\Rector\Assign\NullCoalescingOperatorRector;
use Rector\Php74\Rector\If_\IfToNullCoalescingAssignRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php81\Rector\Array_\ArrayToFirstClassCallableRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpSets()
    ->withPreparedSets(
        typeDeclarations: true,
    )
    // Absorbing moves code: keep the library source (property declarations,
    // readonly-ness, callables, assignments) exactly as released.
    ->withSkip([
        ArrayToFirstClassCallableRector::class,
        ClassPropertyAssignToConstructorPromotionRector::class,
        FunctionFirstClassCallableRector::class,
        IfToNullCoalescingAssignRector::class,
        NullCoalescingOperatorRector::class,
        ReadOnlyPropertyRector::class,
    ]);
