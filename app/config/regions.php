<?php

use Appwrite\Config\Regions;
use Utopia\System\System;

$regions = System::getEnv('_APP_REGIONS', '');

if (!empty($regions)) {
    return Regions::parse($regions);
}

return [
    'default' => [
        '$id' => 'default',
        'name' => 'default',
        'disabled' => false,
        'default' => true,
    ],
];
