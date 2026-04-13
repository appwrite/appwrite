<?php

namespace Appwrite\Platform\Modules\Account;

use Appwrite\Platform\Modules\Account\Services\Http;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
