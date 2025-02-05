<?php

namespace Appwrite\Platform\Modules\Functions\Services;

use Appwrite\Platform\Modules\Functions\Http\Deployments\Create as CreateDeployment;
use Appwrite\Platform\Modules\Functions\Http\Functions\Create as CreateFunction;
use Appwrite\Platform\Modules\Functions\Http\Functions\Update as UpdateFunction;
use Appwrite\Platform\Modules\Functions\Http\Functions\XList as ListFunctions;
use Appwrite\Platform\Modules\Functions\Http\Runtimes\XList as ListRuntimes;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;
        $this->addAction(CreateFunction::getName(), new CreateFunction());
        $this->addAction(UpdateFunction::getName(), new UpdateFunction());
        $this->addAction(ListFunctions::getName(), new ListFunctions());
        $this->addAction(ListRuntimes::getName(), new ListRuntimes());
        $this->addAction(CreateDeployment::getName(), new CreateDeployment());
    }
}
