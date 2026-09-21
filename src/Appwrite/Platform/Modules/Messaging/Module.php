<?php

namespace Appwrite\Platform\Modules\Messaging;

use Appwrite\Platform\Modules\Messaging\Services\Http;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
