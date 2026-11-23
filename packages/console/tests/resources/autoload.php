<?php

declare(strict_types=1);

$standalone = __DIR__ . '/../../vendor/autoload.php';

require is_file($standalone) ? $standalone : __DIR__ . '/../../../../vendor/autoload.php';
