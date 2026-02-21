<?php

namespace Appwrite\Platform\Modules;

use Appwrite\Platform\Services\Tasks;
use Appwrite\Platform\Services\Workers;
use Utopia\Platform\Module;

class Core extends Module
{
    public function __construct()
    {
        $this->addService('tasks', new Tasks());
        $this->addService('workers', new Workers());
    }

}
