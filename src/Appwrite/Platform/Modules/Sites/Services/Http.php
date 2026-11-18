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
        $this->addAction('createSite', CreateSite::class);
        $this->addAction('getSite', GetSite::class);
        $this->addAction('listSites', ListSites::class);
        $this->addAction('updateSite', UpdateSite::class);
        $this->addAction('deleteSite', DeleteSite::class);

        // Frameworks
        $this->addAction('listFrameworks', ListFrameworks::class);


        // Deployments
        $this->addAction('createDeployment', CreateDeployment::class);
        $this->addAction('createTemplateDeployment', CreateTemplateDeployment::class);
        $this->addAction('createVcsDeployment', CreateVcsDeployment::class);
        $this->addAction('getDeployment', GetDeployment::class);
        $this->addAction('listDeployments', ListDeployments::class);
        $this->addAction('updateSiteDeployment', UpdateSiteDeployment::class);
        $this->addAction('deleteDeployment', DeleteDeployment::class);
        $this->addAction('getDeploymentDownload', DownloadDeployment::class);
        $this->addAction('createDuplicateDeployment', CreateDuplicateDeployment::class);
        $this->addAction('updateDeploymentStatus', UpdateDeploymentStatus::class);

        // Logs
        $this->addAction('getLog', GetLog::class);
        $this->addAction('listLogs', ListLogs::class);
        $this->addAction('deleteLog', DeleteLog::class);

        // Variables
        $this->addAction('createVariable', CreateVariable::class);
        $this->addAction('getVariable', GetVariable::class);
        $this->addAction('listVariables', ListVariables::class);
        $this->addAction('updateVariable', UpdateVariable::class);
        $this->addAction('deleteVariable', DeleteVariable::class);

        // Templates
        $this->addAction('listTemplates', ListTemplates::class);
        $this->addAction('getTemplate', GetTemplate::class);

        $this->addAction('listSpecifications', ListSpecifications::class);
    }
}
