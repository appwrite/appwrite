<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Catch_\ThrowWithPreviousExceptionRector;

return (require __DIR__ . '/../../rector.php')->withSkip([
    ThrowWithPreviousExceptionRector::class,
]);
