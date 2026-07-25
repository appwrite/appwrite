<?php

namespace Appwrite\Platform\Installer;

use Appwrite\Platform\Installer\Services\Http;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
