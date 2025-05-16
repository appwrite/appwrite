<?php

namespace Appwrite\Platform\Modules\Console;

use Appwrite\Platform\Modules\Console\Services\Http;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
