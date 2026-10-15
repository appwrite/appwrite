<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

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
        // Rector's PHP 8.4 printer drops the parentheses in `(new \DateTime())->`
        // whenever it reprints these files.
        __DIR__ . '/src/Adapter/TimeLimit/Appwrite/TablesDB.php',
        __DIR__ . '/src/Adapter/TimeLimit/Database.php',
    ]);
