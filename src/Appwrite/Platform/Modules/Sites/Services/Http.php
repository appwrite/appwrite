<?php

namespace Appwrite\Platform\Modules\Sites\Services;

use Appwrite\Platform\Modules\Sites\Http\Deployments\Create as CreateDeployment;
use Appwrite\Platform\Modules\Sites\Http\Deployments\Delete as DeleteDeployment;
use Appwrite\Platform\Modules\Sites\Http\Deployments\Download\Get as DownloadDeployment;
use Appwrite\Platform\Modules\Sites\Http\Deployments\Duplicate\Create as CreateDuplicateDeployment;
use Appwrite\Platform\Modules\Sites\Http\Deployments\Get as GetDeployment;
use Appwrite\Platform\Modules\Sites\Http\Deployments\Status\Update as UpdateDeploymentStatus;
use Appwrite\Platform\Modules\Sites\Http\Deployments\Template\Create as CreateTemplateDeployment;
use Appwrite\Platform\Modules\Sites\Http\Deployments\Vcs\Create as CreateVcsDeployment;
use Appwrite\Platform\Modules\Sites\Http\Deployments\XList as ListDeployments;
use Appwrite\Platform\Modules\Sites\Http\Frameworks\XList as ListFrameworks;
use Appwrite\Platform\Modules\Sites\Http\Logs\Delete as DeleteLog;
use Appwrite\Platform\Modules\Sites\Http\Logs\Get as GetLog;
use Appwrite\Platform\Modules\Sites\Http\Logs\XList as ListLogs;
use Appwrite\Platform\Modules\Sites\Http\Sites\Create as CreateSite;
use Appwrite\Platform\Modules\Sites\Http\Sites\Delete as DeleteSite;
use Appwrite\Platform\Modules\Sites\Http\Sites\Deployment\Update as UpdateSiteDeployment;
use Appwrite\Platform\Modules\Sites\Http\Sites\Get as GetSite;
use Appwrite\Platform\Modules\Sites\Http\Sites\Update as UpdateSite;
use Appwrite\Platform\Modules\Sites\Http\Sites\XList as ListSites;
use Appwrite\Platform\Modules\Sites\Http\Specifications\XList as ListSpecifications;
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
        $this->addAction(CreateTemplateDeployment::getName(), new CreateTemplateDeployment());
        $this->addAction(CreateVcsDeployment::getName(), new CreateVcsDeployment());
        $this->addAction(GetDeployment::getName(), new GetDeployment());
        $this->addAction(ListDeployments::getName(), new ListDeployments());
        $this->addAction(UpdateSiteDeployment::getName(), new UpdateSiteDeployment());
        $this->addAction(DeleteDeployment::getName(), new DeleteDeployment());
        $this->addAction(DownloadDeployment::getName(), new DownloadDeployment());
        $this->addAction(CreateDuplicateDeployment::getName(), new CreateDuplicateDeployment());
        $this->addAction(UpdateDeploymentStatus::getName(), new UpdateDeploymentStatus());

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

        $this->addAction(ListSpecifications::getName(), new ListSpecifications());
    }
}
