<?php

declare(strict_types=1);

use Utopia\Console;

include __DIR__ . '/../../vendor/autoload.php';

Console::loop(function (): void {
    echo "Hello\n";
});
