<?php

namespace Appwrite\Platform\Modules\Organization\Services;

use Appwrite\Platform\Modules\Organization\Http\Init as Init;
use Appwrite\Platform\Modules\Organization\Http\Projects\Create as CreateProject;
use Appwrite\Platform\Modules\Organization\Http\Projects\Delete as DeleteProject;
use Appwrite\Platform\Modules\Organization\Http\Projects\Get as GetProject;
use Appwrite\Platform\Modules\Organization\Http\Projects\Update as UpdateProject;
use Appwrite\Platform\Modules\Organization\Http\Projects\XList as ListProjects;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        // Init hook
        $this->addAction(Init::getName(), new Init());

        // Projects
        $this->addAction(CreateProject::getName(), new CreateProject());
        $this->addAction(ListProjects::getName(), new ListProjects());
        $this->addAction(GetProject::getName(), new GetProject());
        $this->addAction(UpdateProject::getName(), new UpdateProject());
        $this->addAction(DeleteProject::getName(), new DeleteProject());
    }
}
