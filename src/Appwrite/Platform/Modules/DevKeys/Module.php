<?php

namespace Appwrite\Platform\Modules\DevKeys;

use Appwrite\Platform\Modules\DevKeys\Services\Http;
use Utopia\Platform\Module as Base;

class Module extends Base
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
