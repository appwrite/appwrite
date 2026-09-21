<?php

namespace Appwrite\Platform\Modules\Presences;

use Appwrite\Platform\Modules\Presences\Services\Http;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
