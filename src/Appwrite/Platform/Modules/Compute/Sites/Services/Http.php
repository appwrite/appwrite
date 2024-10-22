<?php

namespace Appwrite\Platform\Modules\Compute\Sites\Services;

use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;
    }
}
