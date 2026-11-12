<?php

namespace Appwrite\Platform\Modules\Usage\Services;

use Appwrite\Platform\Tasks\StatsResources;
use Appwrite\Platform\Tasks\UsageSetup;
use Utopia\Platform\Service;

class Tasks extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_TASK;
        $this->addAction('stats-resources', StatsResources::class);
        $this->addAction('usage-setup', UsageSetup::class);
    }
}
