<?php

use Utopia\Image\Image;
use Utopia\System\System;

if (\class_exists('Imagick')) {
    Image::setResourceLimit('memory', intval(System::getEnv('_APP_IMAGES_RESOURCE_LIMIT_MEMORY', 1024*1024*64)));
}
