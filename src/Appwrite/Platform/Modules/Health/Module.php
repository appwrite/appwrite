<?php

namespace Appwrite\Platform\Modules\Health;

use Appwrite\Platform\Modules\Health\Services\Http;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
