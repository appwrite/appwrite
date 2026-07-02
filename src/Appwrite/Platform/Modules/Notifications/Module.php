<?php

namespace Appwrite\Platform\Modules\Notifications;

use Appwrite\Platform\Modules\Notifications\Services\Http;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
