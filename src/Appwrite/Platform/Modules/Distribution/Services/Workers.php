<?php

namespace Appwrite\Platform\Modules\Distribution\Services;

use Appwrite\Platform\Modules\Distribution\Workers\Builds;
use Utopia\Platform\Service;

class Workers extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_WORKER;
        $this->addAction(Builds::getName(), Builds::class);
    }
}
