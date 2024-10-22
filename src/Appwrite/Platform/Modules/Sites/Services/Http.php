<?php

namespace Appwrite\Platform\Modules\Sites\Services;

use Appwrite\Platform\Modules\Sites\Http\Deployments\CreateDeployment;
use Appwrite\Platform\Modules\Sites\Http\Sites\CreateSite;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;
        $this->addAction(CreateSite::getName(), new CreateSite());
        $this->addAction(CreateDeployment::getName(), new CreateDeployment());
    }
}
