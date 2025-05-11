<?php

namespace Appwrite\Platform\Modules\Tokens;

use Appwrite\Platform\Modules\Tokens\Services\Http;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
