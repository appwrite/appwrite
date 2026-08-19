<?php

namespace Appwrite\Platform\Modules\Usage;

use Appwrite\Platform\Modules\Usage\Services\Http;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
