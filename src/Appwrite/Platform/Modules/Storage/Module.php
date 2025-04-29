<?php

namespace Appwrite\Platform\Modules\Storage;

use Appwrite\Platform\Modules\Storage\Services\Http;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
