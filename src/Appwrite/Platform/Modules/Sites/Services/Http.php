<?php

namespace Appwrite\Platform\Modules\Sites\Services;

use Appwrite\Platform\Modules\Sites\Http\Deployments\Builds\Create as CreateBuild;
use Appwrite\Platform\Modules\Sites\Http\Deployments\Builds\Download\Get as DownloadBuild;
use Appwrite\Platform\Modules\Sites\Http\Deployments\Builds\Update as UpdateBuild;
use Appwrite\Platform\Modules\Sites\Http\Deployments\Create as CreateDeployment;
use Appwrite\Platform\Modules\Sites\Http\Deployments\Delete as DeleteDeployment;
use Appwrite\Platform\Modules\Sites\Http\Deployments\Download\Get as DownloadDeployment;
use Appwrite\Platform\Modules\Sites\Http\Deployments\Get as GetDeployment;
use Appwrite\Platform\Modules\Sites\Http\Deployments\Update as UpdateDeployment;
use Appwrite\Platform\Modules\Sites\Http\Deployments\XList as ListDeployments;
use Appwrite\Platform\Modules\Sites\Http\Frameworks\XList as ListFrameworks;
use Appwrite\Platform\Modules\Sites\Http\Logs\Delete as DeleteLog;
use Appwrite\Platform\Modules\Sites\Http\Logs\Get as GetLog;
use Appwrite\Platform\Modules\Sites\Http\Logs\XList as ListLogs;
use Appwrite\Platform\Modules\Sites\Http\Sites\Create as CreateSite;
use Appwrite\Platform\Modules\Sites\Http\Sites\Delete as DeleteSite;
use Appwrite\Platform\Modules\Sites\Http\Sites\Get as GetSite;
use Appwrite\Platform\Modules\Sites\Http\Sites\Update as UpdateSite;
use Appwrite\Platform\Modules\Sites\Http\Sites\XList as ListSites;
use Appwrite\Platform\Modules\Sites\Http\Templates\Get as GetTemplate;
use Appwrite\Platform\Modules\Sites\Http\Templates\XList as ListTemplates;
use Appwrite\Platform\Modules\Sites\Http\Usage\Get as GetUsage;
use Appwrite\Platform\Modules\Sites\Http\Usage\XList as ListUsage;
use Appwrite\Platform\Modules\Sites\Http\Variables\Create as CreateVariable;
use Appwrite\Platform\Modules\Sites\Http\Variables\Delete as DeleteVariable;
use Appwrite\Platform\Modules\Sites\Http\Variables\Get as GetVariable;
use Appwrite\Platform\Modules\Sites\Http\Variables\Update as UpdateVariable;
use Appwrite\Platform\Modules\Sites\Http\Variables\XList as ListVariables;
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
        $this->addAction(CreateBuild::getName(), new CreateBuild());
        $this->addAction(UpdateBuild::getName(), new UpdateBuild());

        // Logs
        $this->addAction(GetLog::getName(), new GetLog());
        $this->addAction(ListLogs::getName(), new ListLogs());
        $this->addAction(DeleteLog::getName(), new DeleteLog());

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
        $this->addAction(ListUsage::getName(), new ListUsage());
        $this->addAction(GetUsage::getName(), new GetUsage());
    }
}
