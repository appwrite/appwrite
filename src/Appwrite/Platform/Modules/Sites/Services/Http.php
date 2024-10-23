<?php

namespace Appwrite\Platform\Modules\Sites\Services;

use Appwrite\Platform\Modules\Sites\Http\Deployments\CancelDeployment;
use Appwrite\Platform\Modules\Sites\Http\Deployments\CreateDeployment;
use Appwrite\Platform\Modules\Sites\Http\Deployments\DeleteDeployment;
use Appwrite\Platform\Modules\Sites\Http\Deployments\DownloadDeployment;
use Appwrite\Platform\Modules\Sites\Http\Deployments\GetDeployment;
use Appwrite\Platform\Modules\Sites\Http\Deployments\ListDeployments;
use Appwrite\Platform\Modules\Sites\Http\Deployments\RebuildDeployment;
use Appwrite\Platform\Modules\Sites\Http\Deployments\UpdateDeployment;
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
        $this->addAction(GetDeployment::getName(), new GetDeployment());
        $this->addAction(ListDeployments::getName(), new ListDeployments());
        $this->addAction(UpdateDeployment::getName(), new UpdateDeployment());
        $this->addAction(DeleteDeployment::getName(), new DeleteDeployment());
        $this->addAction(DownloadDeployment::getName(), new DownloadDeployment());
        $this->addAction(RebuildDeployment::getName(), new RebuildDeployment());
        $this->addAction(CancelDeployment::getName(), new CancelDeployment());
    }
}
