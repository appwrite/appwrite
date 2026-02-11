<?php

namespace Appwrite\Platform\Modules\Logs;

use Appwrite\Platform\Modules\Logs\Services\Http;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
