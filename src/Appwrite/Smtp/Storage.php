<?php

declare(strict_types=1);

namespace Appwrite\Smtp;

use Utopia\Storage\Device;

final class Storage
{
    public function getDevice(string $projectId): Device
    {
        return \getDevice(APP_STORAGE_FUNCTIONS.'/app-'.$projectId);
    }
}
