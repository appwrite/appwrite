<?php

namespace Appwrite\Platform\Modules\Sites\Services;

use Appwrite\Platform\Modules\Sites\Workers\Insights;
use Utopia\Platform\Service;

class Workers extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_WORKER;
        $this->addAction(Insights::getName(), new Insights());
    }
}
