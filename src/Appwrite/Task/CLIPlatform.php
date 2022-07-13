<?php

namespace Appwrite\Task;

use Utopia\Platform\Platform;

class CLIPlatform extends Platform {
    public function __construct()
    {
        $this->addService('cliTasks', new Tasks());
    }
}