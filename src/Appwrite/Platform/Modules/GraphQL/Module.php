<?php

namespace Appwrite\Platform\Modules\GraphQL;

use Appwrite\Platform\Modules\GraphQL\Services\Http;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
