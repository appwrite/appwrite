<?php

declare(strict_types=1);

use Rector\DeadCode\Rector\Node\RemoveNonExistingVarAnnotationRector;

// Keeps the `@var T` generic hint createMeter() needs for phpstan (level max).
return (require __DIR__ . '/../../rector.php')->withSkip([
    RemoveNonExistingVarAnnotationRector::class,
]);
