<?php

namespace Appwrite\Platform\Modules\Functions\Services;

use Appwrite\Platform\Modules\Functions\Http\Deployments\Create as CreateDeployment;
use Appwrite\Platform\Modules\Functions\Http\Deployments\Delete as DeleteDeployment;
use Appwrite\Platform\Modules\Functions\Http\Deployments\Download\Get as DownloadDeployment;
use Appwrite\Platform\Modules\Functions\Http\Deployments\Duplicate\Create as CreateDuplicateDeployment;
use Appwrite\Platform\Modules\Functions\Http\Deployments\Get as GetDeployment;
use Appwrite\Platform\Modules\Functions\Http\Deployments\Status\Update as UpdateDeploymentStatus;
use Appwrite\Platform\Modules\Functions\Http\Deployments\Template\Create as CreateTemplateDeployment;
use Appwrite\Platform\Modules\Functions\Http\Deployments\Vcs\Create as CreateVcsDeployment;
use Appwrite\Platform\Modules\Functions\Http\Deployments\XList as ListDeployments;
use Appwrite\Platform\Modules\Functions\Http\Executions\Create as CreateExecution;
use Appwrite\Platform\Modules\Functions\Http\Executions\Delete as DeleteExecution;
use Appwrite\Platform\Modules\Functions\Http\Executions\Get as GetExecution;
use Appwrite\Platform\Modules\Functions\Http\Executions\XList as ListExecutions;
use Appwrite\Platform\Modules\Functions\Http\Functions\Create as CreateFunction;
use Appwrite\Platform\Modules\Functions\Http\Functions\Delete as DeleteFunction;
use Appwrite\Platform\Modules\Functions\Http\Functions\Deployment\Update as UpdateFunctionDeployment;
use Appwrite\Platform\Modules\Functions\Http\Functions\Get as GetFunction;
use Appwrite\Platform\Modules\Functions\Http\Functions\Update as UpdateFunction;
use Appwrite\Platform\Modules\Functions\Http\Functions\XList as ListFunctions;
use Appwrite\Platform\Modules\Functions\Http\Jobs\Event\Create as CreateJobEvent;
use Appwrite\Platform\Modules\Functions\Http\Runtimes\XList as ListRuntimes;
use Appwrite\Platform\Modules\Functions\Http\Specifications\XList as ListSpecifications;
use Appwrite\Platform\Modules\Functions\Http\Templates\Get as GetTemplate;
use Appwrite\Platform\Modules\Functions\Http\Templates\XList as ListTemplates;
use Appwrite\Platform\Modules\Functions\Http\Variables\Create as CreateVariable;
use Appwrite\Platform\Modules\Functions\Http\Variables\Delete as DeleteVariable;
use Appwrite\Platform\Modules\Functions\Http\Variables\Get as GetVariable;
use Appwrite\Platform\Modules\Functions\Http\Variables\Update as UpdateVariable;
use Appwrite\Platform\Modules\Functions\Http\Variables\XList as ListVariables;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        // Functions
        $this->addAction('createFunction', CreateFunction::class);
        $this->addAction('getFunction', GetFunction::class);
        $this->addAction('updateFunction', UpdateFunction::class);
        $this->addAction('listFunctions', ListFunctions::class);
        $this->addAction('deleteFunction', DeleteFunction::class);

        // Runtimes
        $this->addAction('listRuntimes', ListRuntimes::class);

        // Specifications
        $this->addAction('listSpecifications', ListSpecifications::class);

        // Deployments
        $this->addAction('createDeployment', CreateDeployment::class);
        $this->addAction('getDeployment', GetDeployment::class);
        $this->addAction('updateFunctionDeployment', UpdateFunctionDeployment::class);
        $this->addAction('listDeployments', ListDeployments::class);
        $this->addAction('deleteDeployment', DeleteDeployment::class);
        $this->addAction('createTemplateDeployment', CreateTemplateDeployment::class);
        $this->addAction('createVcsDeployment', CreateVcsDeployment::class);
        $this->addAction('getDeploymentDownload', DownloadDeployment::class);
        $this->addAction('createDuplicateDeployment', CreateDuplicateDeployment::class);
        $this->addAction('updateDeploymentStatus', UpdateDeploymentStatus::class);

        // Jobs (open-runtimes jobs-service build backend)
        $this->addAction('createJobEvent', CreateJobEvent::class);

        // Executions
        $this->addAction('createExecution', CreateExecution::class);
        $this->addAction('getExecution', GetExecution::class);
        $this->addAction('listExecutions', ListExecutions::class);
        $this->addAction('deleteExecution', DeleteExecution::class);

        // Variables
        $this->addAction('createVariable', CreateVariable::class);
        $this->addAction('getVariable', GetVariable::class);
        $this->addAction('listVariables', ListVariables::class);
        $this->addAction('updateVariable', UpdateVariable::class);
        $this->addAction('deleteVariable', DeleteVariable::class);

        // Templates
        $this->addAction('getTemplate', GetTemplate::class);
        $this->addAction('listTemplates', ListTemplates::class);
    }
}
