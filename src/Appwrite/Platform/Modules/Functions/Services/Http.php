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
        $this->addAction(CreateFunction::getName(), CreateFunction::class);
        $this->addAction(GetFunction::getName(), GetFunction::class);
        $this->addAction(UpdateFunction::getName(), UpdateFunction::class);
        $this->addAction(ListFunctions::getName(), ListFunctions::class);
        $this->addAction(DeleteFunction::getName(), DeleteFunction::class);

        // Runtimes
        $this->addAction(ListRuntimes::getName(), ListRuntimes::class);

        // Specifications
        $this->addAction(ListSpecifications::getName(), ListSpecifications::class);

        // Deployments
        $this->addAction(CreateDeployment::getName(), CreateDeployment::class);
        $this->addAction(GetDeployment::getName(), GetDeployment::class);
        $this->addAction(UpdateFunctionDeployment::getName(), UpdateFunctionDeployment::class);
        $this->addAction(ListDeployments::getName(), ListDeployments::class);
        $this->addAction(DeleteDeployment::getName(), DeleteDeployment::class);
        $this->addAction(CreateTemplateDeployment::getName(), CreateTemplateDeployment::class);
        $this->addAction(CreateVcsDeployment::getName(), CreateVcsDeployment::class);
        $this->addAction(DownloadDeployment::getName(), DownloadDeployment::class);
        $this->addAction(CreateDuplicateDeployment::getName(), CreateDuplicateDeployment::class);
        $this->addAction(UpdateDeploymentStatus::getName(), UpdateDeploymentStatus::class);

        // Jobs (open-runtimes jobs-service build backend)
        $this->addAction(CreateJobEvent::getName(), CreateJobEvent::class);

        // Executions
        $this->addAction(CreateExecution::getName(), CreateExecution::class);
        $this->addAction(GetExecution::getName(), GetExecution::class);
        $this->addAction(ListExecutions::getName(), ListExecutions::class);
        $this->addAction(DeleteExecution::getName(), DeleteExecution::class);

        // Variables
        $this->addAction(CreateVariable::getName(), CreateVariable::class);
        $this->addAction(GetVariable::getName(), GetVariable::class);
        $this->addAction(ListVariables::getName(), ListVariables::class);
        $this->addAction(UpdateVariable::getName(), UpdateVariable::class);
        $this->addAction(DeleteVariable::getName(), DeleteVariable::class);

        // Templates
        $this->addAction(GetTemplate::getName(), GetTemplate::class);
        $this->addAction(ListTemplates::getName(), ListTemplates::class);
    }
}
