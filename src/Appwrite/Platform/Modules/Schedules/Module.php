<?php

namespace Appwrite\Platform\Modules\Schedules;

use Appwrite\Platform\Modules\Schedules\Services\Http;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
