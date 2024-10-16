<?php

namespace Appwrite\Platform;

use Appwrite\Platform\Modules\Core;
use Appwrite\Platform\Modules\FunctionsVariables;
use Appwrite\Platform\Modules\ProjectsVariables;
use Utopia\Platform\Platform;

class Appwrite extends Platform
{
    public function __construct()
    {
        parent::__construct(new Core());
        $this->addModule(new FunctionsVariables());
        $this->addModule(new ProjectsVariables());
    }
}
