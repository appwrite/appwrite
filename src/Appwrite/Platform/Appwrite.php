<?php

namespace Appwrite\Platform;

use Utopia\Platform\Platform;

class Appwrite extends Platform
{
    public function __construct()
    {
        $this->addService('tasks', new Tasks());
    }
}
