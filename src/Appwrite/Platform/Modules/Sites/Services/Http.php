<?php

namespace Appwrite\Platform\Modules\Sites\Services;

use Appwrite\Platform\Modules\Sites\Http\Deployments\CancelDeployment;
use Appwrite\Platform\Modules\Sites\Http\Deployments\CreateDeployment;
use Appwrite\Platform\Modules\Sites\Http\Deployments\DeleteDeployment;
use Appwrite\Platform\Modules\Sites\Http\Deployments\DownloadBuild;
use Appwrite\Platform\Modules\Sites\Http\Deployments\DownloadDeployment;
use Appwrite\Platform\Modules\Sites\Http\Deployments\GetDeployment;
use Appwrite\Platform\Modules\Sites\Http\Deployments\ListDeployments;
use Appwrite\Platform\Modules\Sites\Http\Deployments\RebuildDeployment;
use Appwrite\Platform\Modules\Sites\Http\Deployments\UpdateDeployment;
use Appwrite\Platform\Modules\Sites\Http\Sites\CreateSite;
use Appwrite\Platform\Modules\Sites\Http\Sites\DeleteSite;
use Appwrite\Platform\Modules\Sites\Http\Sites\GetSite;
use Appwrite\Platform\Modules\Sites\Http\Sites\GetSitesUsage;
use Appwrite\Platform\Modules\Sites\Http\Sites\GetSiteUsage;
use Appwrite\Platform\Modules\Sites\Http\Sites\GetTemplate;
use Appwrite\Platform\Modules\Sites\Http\Sites\ListFrameworks;
use Appwrite\Platform\Modules\Sites\Http\Sites\ListSites;
use Appwrite\Platform\Modules\Sites\Http\Sites\ListTemplates;
use Appwrite\Platform\Modules\Sites\Http\Sites\UpdateSite;
use Appwrite\Platform\Modules\Sites\Http\Variables\CreateVariable;
use Appwrite\Platform\Modules\Sites\Http\Variables\DeleteVariable;
use Appwrite\Platform\Modules\Sites\Http\Variables\GetVariable;
use Appwrite\Platform\Modules\Sites\Http\Variables\ListVariables;
use Appwrite\Platform\Modules\Sites\Http\Variables\UpdateVariable;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;
        // Sites
        $this->addAction(CreateSite::getName(), new CreateSite());
        $this->addAction(GetSite::getName(), new GetSite());
        $this->addAction(ListSites::getName(), new ListSites());
        $this->addAction(UpdateSite::getName(), new UpdateSite());
        $this->addAction(DeleteSite::getName(), new DeleteSite());

        // Frameworks
        $this->addAction(ListFrameworks::getName(), new ListFrameworks());


        // Deployments
        $this->addAction(CreateDeployment::getName(), new CreateDeployment());
        $this->addAction(GetDeployment::getName(), new GetDeployment());
        $this->addAction(ListDeployments::getName(), new ListDeployments());
        $this->addAction(UpdateDeployment::getName(), new UpdateDeployment());
        $this->addAction(DeleteDeployment::getName(), new DeleteDeployment());
        $this->addAction(DownloadDeployment::getName(), new DownloadDeployment());
        $this->addAction(DownloadBuild::getName(), new DownloadBuild());
        $this->addAction(RebuildDeployment::getName(), new RebuildDeployment());
        $this->addAction(CancelDeployment::getName(), new CancelDeployment());

        // Variables
        $this->addAction(CreateVariable::getName(), new CreateVariable());
        $this->addAction(GetVariable::getName(), new GetVariable());
        $this->addAction(ListVariables::getName(), new ListVariables());
        $this->addAction(UpdateVariable::getName(), new UpdateVariable());
        $this->addAction(DeleteVariable::getName(), new DeleteVariable());

        // Templates
        $this->addAction(ListTemplates::getName(), new ListTemplates());
        $this->addAction(GetTemplate::getName(), new GetTemplate());

        // Usage
        $this->addAction(GetSiteUsage::getName(), new GetSiteUsage());
        $this->addAction(GetSitesUsage::getName(), new GetSitesUsage());
    }
}
