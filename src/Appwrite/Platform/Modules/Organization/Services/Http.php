<?php

namespace Appwrite\Platform\Modules\Organization\Services;

use Appwrite\Platform\Modules\Organization\Http\Init as Init;
use Appwrite\Platform\Modules\Organization\Http\Projects\Create as CreateProject;
use Appwrite\Platform\Modules\Organization\Http\Projects\Delete as DeleteProject;
use Appwrite\Platform\Modules\Organization\Http\Projects\Get as GetProject;
use Appwrite\Platform\Modules\Organization\Http\Projects\Keys\Create as CreateProjectKey;
use Appwrite\Platform\Modules\Organization\Http\Projects\Keys\Delete as DeleteProjectKey;
use Appwrite\Platform\Modules\Organization\Http\Projects\Keys\Ephemeral\Create as CreateEphemeralProjectKey;
use Appwrite\Platform\Modules\Organization\Http\Projects\Keys\Get as GetProjectKey;
use Appwrite\Platform\Modules\Organization\Http\Projects\Keys\Update as UpdateProjectKey;
use Appwrite\Platform\Modules\Organization\Http\Projects\Keys\XList as ListProjectKeys;
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

        // Project keys
        $this->addAction(CreateProjectKey::getName(), new CreateProjectKey());
        $this->addAction(CreateEphemeralProjectKey::getName(), new CreateEphemeralProjectKey());
        $this->addAction(ListProjectKeys::getName(), new ListProjectKeys());
        $this->addAction(GetProjectKey::getName(), new GetProjectKey());
        $this->addAction(UpdateProjectKey::getName(), new UpdateProjectKey());
        $this->addAction(DeleteProjectKey::getName(), new DeleteProjectKey());
    }
}
