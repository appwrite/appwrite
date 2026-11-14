<?php

namespace Appwrite\Platform\Modules\Videos;

use Appwrite\Platform\Modules\Videos\Services\Http;
use Appwrite\Platform\Modules\Videos\Services\Workers;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
        $this->addService('workers', new Workers());
    }
}
