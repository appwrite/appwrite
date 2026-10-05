<?php

namespace Appwrite\Platform\Modules\Usage\Services;

use Appwrite\Platform\Workers\StatsResources;
use Appwrite\Platform\Workers\StatsUsage;
use Utopia\Platform\Service;

class Workers extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_WORKER;
        $this->addAction(StatsUsage::getName(), new StatsUsage());
        $this->addAction(StatsResources::getName(), new StatsResources());
    }
}
