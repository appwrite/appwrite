<?php

namespace Appwrite\Platform\Modules\Badge;

use Appwrite\Platform\Modules\Badge\Services\Http;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
