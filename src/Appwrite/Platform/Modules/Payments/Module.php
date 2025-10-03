<?php

namespace Appwrite\Platform\Modules\Payments;

use Appwrite\Platform\Modules\Payments\Services\Http;
use Appwrite\Platform\Modules\Payments\Services\Workers;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
        $this->addService('workers', new Workers());
    }
}


