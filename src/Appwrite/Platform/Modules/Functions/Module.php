<?php

namespace Appwrite\Platform\Modules\Functions;

use Appwrite\Platform\Modules\Functions\Services\Http;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
