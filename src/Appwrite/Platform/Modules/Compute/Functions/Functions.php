<?php

namespace Appwrite\Platform\Modules\Compute\Functions;

use Appwrite\Platform\Modules\Compute\Functions\Services\Http;
use Utopia\Platform\Module;

class Functions extends Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
