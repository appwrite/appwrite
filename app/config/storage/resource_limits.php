<?php

use Utopia\Config\Config;
use Utopia\Image\Limits;
use Utopia\System\System;

// What one image may cost to decode and transform. The memory default is what the pixel
// cache allowed before limits were per image: 64 MiB in memory and 512 MiB mapped.
// A value that is not a positive number falls back to its default: this file loads in
// every process, and a typo here should not stop the platform from starting.
$limit = function (string $name, int $default): int {
    $value = (int) System::getEnv($name, $default);

    return $value > 0 ? $value : $default;
};

Config::setParam('storage-image-limits', new Limits(
    width: $limit('_APP_IMAGES_RESOURCE_LIMIT_WIDTH', 50000),
    height: $limit('_APP_IMAGES_RESOURCE_LIMIT_HEIGHT', 50000),
    memory: $limit('_APP_IMAGES_RESOURCE_LIMIT_MEMORY', 576 * 1024 * 1024),
));
