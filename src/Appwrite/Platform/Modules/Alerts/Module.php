<?php

namespace Appwrite\Platform\Modules\Alerts;

use Appwrite\Platform\Modules\Alerts\Services\Http;
use Appwrite\Platform\Modules\Alerts\Services\Workers;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
        $this->addService('workers', new Workers());
    }
}
