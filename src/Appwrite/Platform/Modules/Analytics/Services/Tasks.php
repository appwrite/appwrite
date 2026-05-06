<?php

namespace Appwrite\Platform\Modules\Analytics\Services;

use Appwrite\Platform\Modules\Analytics\Tasks\Setup;
use Utopia\Platform\Service;

class Tasks extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_TASK;

        $this->addAction(Setup::getName(), new Setup());
    }
}
