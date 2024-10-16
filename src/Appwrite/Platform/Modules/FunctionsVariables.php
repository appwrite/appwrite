<?php

namespace Appwrite\Platform\Modules;

use Appwrite\Platform\Modules\FunctionsVariables\Services\Http;
use Utopia\Platform\Module;

class FunctionsVariables extends Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
