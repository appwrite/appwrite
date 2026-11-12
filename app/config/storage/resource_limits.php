<?php

use Utopia\Image\Image;
use Utopia\System\System;

// The class comes from the utopia extension, which tooling run outside the image does not load.
if (\class_exists(Image::class)) {
    Image::setResourceLimit('memory', intval(System::getEnv('_APP_IMAGES_RESOURCE_LIMIT_MEMORY', 1024 * 1024 * 64)));
}
