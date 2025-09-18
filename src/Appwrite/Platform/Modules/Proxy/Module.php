<?php

namespace Appwrite\Platform\Modules\Proxy;

use Appwrite\Platform\Modules\Proxy\Services\Http;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
