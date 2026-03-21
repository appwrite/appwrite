<?php

namespace Appwrite\Platform\Modules\Avatars;

use Appwrite\Platform\Modules\Avatars\Services\Http;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
