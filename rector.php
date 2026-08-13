<?php

declare(strict_types=1);

// Everything the root baseline runs, plus the remaining stable prepared sets.
return (require __DIR__ . '/../../rector.php')->withPreparedSets(
    typeDeclarationDocblocks: true,
    privatization: true,
    instanceOf: true,
    rectorPreset: true,
);
