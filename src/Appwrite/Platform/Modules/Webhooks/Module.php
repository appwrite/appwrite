<?php

namespace Appwrite\Platform\Modules\Webhooks;

use Appwrite\Platform\Modules\Webhooks\Services\Http;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
