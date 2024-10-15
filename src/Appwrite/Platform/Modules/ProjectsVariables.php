<?php

namespace Appwrite\Platform\Modules;

use Appwrite\Platform\Modules\ProjectsVariables\Services\Http;
use Utopia\Platform\Module;

class ProjectsVariables extends Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
