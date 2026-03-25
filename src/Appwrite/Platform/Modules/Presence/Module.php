<?php

namespace Appwrite\Platform\Modules\Presence;

use Appwrite\Platform\Modules\Presence\Services\Http;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
