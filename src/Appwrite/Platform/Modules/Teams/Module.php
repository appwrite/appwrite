<?php

namespace Appwrite\Platform\Modules\Teams;

use Appwrite\Platform\Modules\Teams\Services\Http;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
