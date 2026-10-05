<?php

namespace Appwrite\Platform\Modules\Usage;

use Appwrite\Platform\Modules\Usage\Services\Http;
use Appwrite\Platform\Modules\Usage\Services\Tasks;
use Appwrite\Platform\Modules\Usage\Services\Workers;
use Utopia\Platform;

/**
 * Cloud composes Core() plus its own modules and never registers this one, so
 * the workers and tasks here stay self-hosted without an edition check. Cloud
 * keeps its own billing-aware implementations under the same names.
 */
class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
        $this->addService('workers', new Workers());
        $this->addService('tasks', new Tasks());
    }
}
