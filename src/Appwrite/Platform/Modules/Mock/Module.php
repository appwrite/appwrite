<?php

namespace Appwrite\Platform\Modules\Mock;

use Appwrite\Platform\Modules\Mock\Services\Http;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
