<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php70\Rector\FuncCall\RandomFunctionRector;
use Rector\Php70\Rector\MethodCall\ThisCallOnStaticMethodToStaticCallRector;
use Rector\Php70\Rector\Ternary\TernaryToNullCoalescingRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\TypeDeclaration\Rector\Empty_\EmptyOnNullableObjectToInstanceOfRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpSets()
    ->withPreparedSets(
        typeDeclarations: true,
    )
    // Absorbing moves code: keep src exactly as released. Constructor
    // promotion would also rename the named arguments callers pass.
    ->withSkip([
        ClassPropertyAssignToConstructorPromotionRector::class => [__DIR__ . '/src'],
        EmptyOnNullableObjectToInstanceOfRector::class => [__DIR__ . '/src'],
        RandomFunctionRector::class => [__DIR__ . '/src'],
        TernaryToNullCoalescingRector::class => [__DIR__ . '/src'],
        ThisCallOnStaticMethodToStaticCallRector::class => [__DIR__ . '/src'],
    ]);
