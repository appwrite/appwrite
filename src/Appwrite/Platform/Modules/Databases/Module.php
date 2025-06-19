<?php

namespace Appwrite\Platform\Modules\Databases;

require_once __DIR__ . '/Constants.php';

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
