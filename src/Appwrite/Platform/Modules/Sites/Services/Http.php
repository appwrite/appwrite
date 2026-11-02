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
        $this->addAction(CreateSite::getName(), CreateSite::class);
        $this->addAction(GetSite::getName(), GetSite::class);
        $this->addAction(ListSites::getName(), ListSites::class);
        $this->addAction(UpdateSite::getName(), UpdateSite::class);
        $this->addAction(DeleteSite::getName(), DeleteSite::class);

        // Frameworks
        $this->addAction(ListFrameworks::getName(), ListFrameworks::class);


        // Deployments
        $this->addAction(CreateDeployment::getName(), CreateDeployment::class);
        $this->addAction(CreateTemplateDeployment::getName(), CreateTemplateDeployment::class);
        $this->addAction(CreateVcsDeployment::getName(), CreateVcsDeployment::class);
        $this->addAction(GetDeployment::getName(), GetDeployment::class);
        $this->addAction(ListDeployments::getName(), ListDeployments::class);
        $this->addAction(UpdateSiteDeployment::getName(), UpdateSiteDeployment::class);
        $this->addAction(DeleteDeployment::getName(), DeleteDeployment::class);
        $this->addAction(DownloadDeployment::getName(), DownloadDeployment::class);
        $this->addAction(CreateDuplicateDeployment::getName(), CreateDuplicateDeployment::class);
        $this->addAction(UpdateDeploymentStatus::getName(), UpdateDeploymentStatus::class);

        // Logs
        $this->addAction(GetLog::getName(), GetLog::class);
        $this->addAction(ListLogs::getName(), ListLogs::class);
        $this->addAction(DeleteLog::getName(), DeleteLog::class);

        // Variables
        $this->addAction(CreateVariable::getName(), CreateVariable::class);
        $this->addAction(GetVariable::getName(), GetVariable::class);
        $this->addAction(ListVariables::getName(), ListVariables::class);
        $this->addAction(UpdateVariable::getName(), UpdateVariable::class);
        $this->addAction(DeleteVariable::getName(), DeleteVariable::class);

        // Templates
        $this->addAction(ListTemplates::getName(), ListTemplates::class);
        $this->addAction(GetTemplate::getName(), GetTemplate::class);

        $this->addAction(ListSpecifications::getName(), ListSpecifications::class);
    }
}
