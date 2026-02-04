<?php

namespace Appwrite\Platform\Modules\VCS;

use Appwrite\Platform\Modules\VCS\Services\Http;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}