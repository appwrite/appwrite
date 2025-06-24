<?php

namespace Appwrite\Platform\Modules\Functions\Services;

use Appwrite\Platform\Modules\Functions\Workers\Builds\Builds;
use Utopia\Platform\Service;

class Workers extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_WORKER;
        new Builds($this);
    }
}
