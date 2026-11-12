<?php

use Utopia\Config\Config;
use Utopia\Image\Limits;
use Utopia\System\System;

// What one image may cost to decode and transform. The memory default is what the pixel
// cache allowed before limits were per image: 64 MiB in memory and 512 MiB mapped.
Config::setParam('storage-image-limits', new Limits(
    width: (int) System::getEnv('_APP_IMAGES_RESOURCE_LIMIT_WIDTH', 50000),
    height: (int) System::getEnv('_APP_IMAGES_RESOURCE_LIMIT_HEIGHT', 50000),
    memory: (int) System::getEnv('_APP_IMAGES_RESOURCE_LIMIT_MEMORY', 576 * 1024 * 1024),
));
