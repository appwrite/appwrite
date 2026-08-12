<?php

namespace Appwrite\Platform\Modules\Sandbox;

use Appwrite\Platform\Modules\Sandbox\Services\Http;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
