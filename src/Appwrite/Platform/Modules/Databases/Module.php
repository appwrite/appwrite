<?php

namespace Appwrite\Platform\Modules\Databases;

use Appwrite\Platform\Modules\Databases\Services\Http;
use Appwrite\Platform\Modules\Databases\Services\Workers;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
        $this->addService('workers', new Workers());
    }
}
