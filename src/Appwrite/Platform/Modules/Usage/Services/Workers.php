<?php

namespace Appwrite\Platform\Modules\Usage\Services;

use Appwrite\Platform\Workers\StatsCalculations;
use Appwrite\Platform\Workers\StatsEvents;
use Appwrite\Platform\Workers\StatsUsage;
use Utopia\Platform\Service;

class Workers extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_WORKER;
        $this->addAction(StatsUsage::getName(), new StatsUsage());
        $this->addAction(StatsCalculations::getName(), new StatsCalculations());
        $this->addAction(StatsEvents::getName(), new StatsEvents());
    }
}
