<?php

namespace Appwrite\Platform\Modules;

use Appwrite\Platform\Modules\DevelopmentKeys\Services\Http;
use Utopia\Platform\Module;

class DevelopmentKeys extends Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
