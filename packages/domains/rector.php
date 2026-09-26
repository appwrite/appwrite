<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\TypeDeclaration\Rector\BooleanAnd\BinaryOpNullableToInstanceofRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpSets()
    ->withPreparedSets(
        typeDeclarations: true,
    )
    ->withSkip([
        BinaryOpNullableToInstanceofRector::class,
        ClassPropertyAssignToConstructorPromotionRector::class,
        ReadOnlyPropertyRector::class,
    ]);
