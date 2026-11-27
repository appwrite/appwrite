<?php

namespace Appwrite\Platform\Modules\Distribution;

use Appwrite\Platform\Modules\Distribution\Services\Http;
use Appwrite\Platform\Modules\Distribution\Services\Workers;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
        $this->addService('workers', new Workers());
    }
}
