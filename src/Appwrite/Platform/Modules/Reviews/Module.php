<?php

namespace Appwrite\Platform\Modules\Reviews;

use Appwrite\Platform\Modules\Reviews\Services\Http;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
