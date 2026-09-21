<?php

namespace Appwrite\Platform\Modules\Organization;

use Appwrite\Platform\Modules\Organization\Services\Http;
use Utopia\Platform\Module as Base;

class Module extends Base
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
