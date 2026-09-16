<?php

declare(strict_types=1);

return (require __DIR__ . '/../../rector.php')->withPreparedSets(
    codingStyle: true,
    instanceOf: true,
    privatization: true,
);
