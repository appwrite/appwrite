<?php

namespace Appwrite\Platform\Modules\Advisor;

use Appwrite\Platform\Modules\Advisor\Services\Http;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
