<?php

namespace Appwrite\Platform\Modules\Users;

use Appwrite\Platform\Modules\Users\Services\Http;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
