<?php

namespace Appwrite\Platform\Modules\DevelopmentKeys;

use Appwrite\Platform\Modules\DevelopmentKeys\Services\Http;
use Utopia\Platform\Module as Base;

class Module extends Base
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
