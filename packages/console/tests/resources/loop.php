<?php

declare(strict_types=1);

use Utopia\Console;

require __DIR__ . '/autoload.php';

Console::loop(function (): void {
    echo "Hello\n";
});
