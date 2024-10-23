<?php

namespace Appwrite\Platform\Modules\Sites\Services;

use Appwrite\Platform\Modules\Sites\Http\Deployments\CreateDeployment;
use Appwrite\Platform\Modules\Sites\Http\Sites\CreateSite;
use Appwrite\Platform\Modules\Sites\Http\Sites\GetSite;
use Appwrite\Platform\Modules\Sites\Http\Sites\ListFrameworks;
use Appwrite\Platform\Modules\Sites\Http\Sites\ListSites;
use Appwrite\Platform\Modules\Sites\Http\Sites\UpdateSite;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;
        $this->addAction(CreateSite::getName(), new CreateSite());
        $this->addAction(GetSite::getName(), new GetSite());
        $this->addAction(ListSites::getName(), new ListSites());
        $this->addAction(UpdateSite::getName(), new UpdateSite());
        $this->addAction(ListFrameworks::getName(), new ListFrameworks());
        $this->addAction(CreateDeployment::getName(), new CreateDeployment());
    }
}
