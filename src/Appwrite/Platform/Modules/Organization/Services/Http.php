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
use Appwrite\Platform\Modules\Organization\Http\Transfers\Create as CreateProjectTransfer;
use Appwrite\Platform\Modules\Organization\Http\Transfers\Delete as DeleteProjectTransfer;
use Appwrite\Platform\Modules\Organization\Http\Transfers\Get as GetProjectTransfer;
use Appwrite\Platform\Modules\Organization\Http\Transfers\Status\Update as UpdateProjectTransferStatus;
use Appwrite\Platform\Modules\Organization\Http\Transfers\XList as ListProjectTransfers;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        // Init hook
        $this->addAction(Init::getName(), Init::class);

        // Projects
        $this->addAction(CreateProject::getName(), CreateProject::class);
        $this->addAction(ListProjects::getName(), ListProjects::class);
        $this->addAction(GetProject::getName(), GetProject::class);
        $this->addAction(UpdateProject::getName(), UpdateProject::class);
        $this->addAction(DeleteProject::getName(), DeleteProject::class);

        // Project keys
        $this->addAction(CreateProjectKey::getName(), CreateProjectKey::class);
        $this->addAction(CreateEphemeralProjectKey::getName(), CreateEphemeralProjectKey::class);
        $this->addAction(ListProjectKeys::getName(), ListProjectKeys::class);
        $this->addAction(GetProjectKey::getName(), GetProjectKey::class);
        $this->addAction(UpdateProjectKey::getName(), UpdateProjectKey::class);
        $this->addAction(DeleteProjectKey::getName(), DeleteProjectKey::class);

        // Project transfers
        $this->addAction(CreateProjectTransfer::getName(), CreateProjectTransfer::class);
        $this->addAction(ListProjectTransfers::getName(), ListProjectTransfers::class);
        $this->addAction(GetProjectTransfer::getName(), GetProjectTransfer::class);
        $this->addAction(DeleteProjectTransfer::getName(), DeleteProjectTransfer::class);
        $this->addAction(UpdateProjectTransferStatus::getName(), UpdateProjectTransferStatus::class);
    }
}
