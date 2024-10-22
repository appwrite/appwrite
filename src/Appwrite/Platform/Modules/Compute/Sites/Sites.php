<?php

namespace Appwrite\Platform\Modules\Compute\Sites;

use Appwrite\Platform\Modules\Compute\Sites\Services\Http;
use Utopia\Platform\Module;

class Sites extends Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
