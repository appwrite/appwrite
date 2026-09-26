<?php

declare(strict_types=1);

use Utopia\Console;

require __DIR__ . '/autoload.php';

echo Console::confirm('this is a question');
