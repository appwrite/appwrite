<?php

use Utopia\Config\Config;
use Utopia\Image\Limits;
use Utopia\System\System;

// What one image may cost to decode and transform: its largest width and height in pixels,
// and the bytes of decoded pixels it may hold at once (576 MiB is about 150 megapixels).
// The memory variable is new: _APP_IMAGES_RESOURCE_LIMIT_MEMORY was ImageMagick's in-memory
// share of a pixel cache that spilled to disk, so a value set for it would be far too small here.
// A value that is not a positive number falls back to its default: this file loads in
// every process, and a typo here should not stop the platform from starting.
$limit = function (string $name, int $default): int {
    $value = (int) System::getEnv($name, $default);

    return $value > 0 ? $value : $default;
};

Config::setParam('storage-image-limits', new Limits(
    width: $limit('_APP_IMAGES_LIMIT_WIDTH', 50000),
    height: $limit('_APP_IMAGES_LIMIT_HEIGHT', 50000),
    memory: $limit('_APP_IMAGES_LIMIT_MEMORY', 576 * 1024 * 1024),
));
