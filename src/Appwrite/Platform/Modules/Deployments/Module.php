<?php

namespace Appwrite\Platform\Modules\Deployments;

use Appwrite\Platform\Modules\Deployments\Services\Http;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
