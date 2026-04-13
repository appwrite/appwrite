<?php

namespace Appwrite\Platform\Modules\Sites;

use Appwrite\Platform\Modules\Sites\Services\Http;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
