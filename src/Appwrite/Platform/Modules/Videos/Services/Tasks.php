<?php

namespace Appwrite\Platform\Modules\Videos\Services;

use Appwrite\Platform\Modules\Videos\Tasks\CleanStaleVideosResources;
use Utopia\Platform\Service;

class Tasks extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_TASK;
        $this->addAction(CleanStaleVideosResources::getName(), new CleanStaleVideosResources());
    }
}
