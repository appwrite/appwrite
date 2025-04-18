<?php

namespace Appwrite\Platform\Modules\Console\Services;

use Appwrite\Platform\Modules\Console\Http\Resources\Get as GetResourceAvailability;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;
        // Resources
        $this->addAction(GetResourceAvailability::getName(), new GetResourceAvailability());
    }
}
