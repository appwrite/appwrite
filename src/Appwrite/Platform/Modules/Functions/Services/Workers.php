<?php

namespace Appwrite\Platform\Modules\Functions\Services;

use Appwrite\Platform\Modules\Functions\Workers\Builds;
use Utopia\Platform\Service;

class Workers extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_WORKER;
        $this->addAction(Builds::getName(), new Builds());
    }
}
