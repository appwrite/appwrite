<?php

namespace Appwrite\Platform\Modules\Migrations;

use Appwrite\Platform\Modules\Migrations\Services\Http;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
