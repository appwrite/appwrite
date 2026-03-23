<?php

namespace Appwrite\Platform\Modules\Project;

use Appwrite\Platform\Modules\Project\Services\Http;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
