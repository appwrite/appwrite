<?php

namespace Appwrite\Platform\Modules\Functions\Services;

use Appwrite\Platform\Modules\Functions\Workers\Builds;
use Appwrite\Platform\Modules\Functions\Workers\Screenshots;
use Utopia\Platform\Service;

class Workers extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_WORKER;
        $this->addAction(Builds::getName(), new Builds());
        $this->addAction(Screenshots::getName(), new Screenshots());
    }
}
