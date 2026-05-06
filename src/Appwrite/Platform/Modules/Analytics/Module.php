<?php

namespace Appwrite\Platform\Modules\Analytics;

use Appwrite\Platform\Modules\Analytics\Services\Http;
use Appwrite\Platform\Modules\Analytics\Services\Tasks;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
        $this->addService('tasks', new Tasks());
    }
}
