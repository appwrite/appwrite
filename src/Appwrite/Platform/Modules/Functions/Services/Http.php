<?php

namespace Appwrite\Platform\Modules\Functions\Services;

use Appwrite\Platform\Modules\Functions\Http\Deployments\Builds\Create as CreateBuild;
use Appwrite\Platform\Modules\Functions\Http\Deployments\Builds\Update as UpdateBuild;
use Appwrite\Platform\Modules\Functions\Http\Deployments\Create as CreateDeployment;
use Appwrite\Platform\Modules\Functions\Http\Deployments\Delete as DeleteDeployment;
use Appwrite\Platform\Modules\Functions\Http\Deployments\Download\Get as DownloadDeployment;
use Appwrite\Platform\Modules\Functions\Http\Deployments\Get as GetDeployment;
use Appwrite\Platform\Modules\Functions\Http\Deployments\Template\Create as CreateTemplateDeployment;
use Appwrite\Platform\Modules\Functions\Http\Deployments\Update as UpdateDeployment;
use Appwrite\Platform\Modules\Functions\Http\Deployments\Vcs\Create as CreateVcsDeployment;
use Appwrite\Platform\Modules\Functions\Http\Deployments\XList as ListDeployments;
use Appwrite\Platform\Modules\Functions\Http\Executions\Create as CreateExecution;
use Appwrite\Platform\Modules\Functions\Http\Executions\Get as GetExecution;
use Appwrite\Platform\Modules\Functions\Http\Executions\XList as ListExecutions;
use Appwrite\Platform\Modules\Functions\Http\Functions\Create as CreateFunction;
use Appwrite\Platform\Modules\Functions\Http\Functions\Delete as DeleteFunction;
use Appwrite\Platform\Modules\Functions\Http\Functions\Get as GetFunction;
use Appwrite\Platform\Modules\Functions\Http\Functions\Update as UpdateFunction;
use Appwrite\Platform\Modules\Functions\Http\Functions\XList as ListFunctions;
use Appwrite\Platform\Modules\Functions\Http\Runtimes\XList as ListRuntimes;
use Appwrite\Platform\Modules\Functions\Http\Specifications\XList as ListSpecifications;
use Appwrite\Platform\Modules\Functions\Http\Usage\Get as GetUsage;
use Appwrite\Platform\Modules\Functions\Http\Usage\XList as ListUsage;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        // Functions
        $this->addAction(CreateFunction::getName(), new CreateFunction());
        $this->addAction(GetFunction::getName(), new GetFunction());
        $this->addAction(UpdateFunction::getName(), new UpdateFunction());
        $this->addAction(ListFunctions::getName(), new ListFunctions());
        $this->addAction(DeleteFunction::getName(), new DeleteFunction());

        // Runtimes
        $this->addAction(ListRuntimes::getName(), new ListRuntimes());

        // Specifications
        $this->addAction(ListSpecifications::getName(), new ListSpecifications());

        // Deployments
        $this->addAction(CreateDeployment::getName(), new CreateDeployment());
        $this->addAction(GetDeployment::getName(), new GetDeployment());
        $this->addAction(UpdateDeployment::getName(), new UpdateDeployment());
        $this->addAction(ListDeployments::getName(), new ListDeployments());
        $this->addAction(DeleteDeployment::getName(), new DeleteDeployment());
        $this->addAction(CreateTemplateDeployment::getName(), new CreateTemplateDeployment());
        $this->addAction(CreateVcsDeployment::getName(), new CreateVcsDeployment());
        $this->addAction(DownloadDeployment::getName(), new DownloadDeployment());
        $this->addAction(CreateBuild::getName(), new CreateBuild());
        $this->addAction(UpdateBuild::getName(), new UpdateBuild());

        // Executions
        $this->addAction(CreateExecution::getName(), new CreateExecution());
        $this->addAction(GetExecution::getName(), new GetExecution());
        $this->addAction(ListExecutions::getName(), new ListExecutions());

        // Usage
        $this->addAction(GetUsage::getName(), new GetUsage());
        $this->addAction(ListUsage::getName(), new ListUsage());
    }
}
