<?php

namespace Appwrite\CLI;

use Utopia\Platform\Platform;

class Tasks extends Platform
{
    public function __construct()
    {
        $this->addService('cliTasks', new TasksService());
    }
}
