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
        $this->addAction('init', Init::class);

        // Projects
        $this->addAction('createOrganizationProject', CreateProject::class);
        $this->addAction('listOrganizationProjects', ListProjects::class);
        $this->addAction('getOrganizationProject', GetProject::class);
        $this->addAction('updateOrganizationProject', UpdateProject::class);
        $this->addAction('deleteOrganizationProject', DeleteProject::class);

        // Project keys
        $this->addAction('createProjectKey', CreateProjectKey::class);
        $this->addAction('createEphemeralProjectKey', CreateEphemeralProjectKey::class);
        $this->addAction('listProjectKeys', ListProjectKeys::class);
        $this->addAction('getProjectKey', GetProjectKey::class);
        $this->addAction('updateProjectKey', UpdateProjectKey::class);
        $this->addAction('deleteProjectKey', DeleteProjectKey::class);

        // Project transfers
        $this->addAction('createProjectTransfer', CreateProjectTransfer::class);
        $this->addAction('listProjectTransfers', ListProjectTransfers::class);
        $this->addAction('getProjectTransfer', GetProjectTransfer::class);
        $this->addAction('deleteProjectTransfer', DeleteProjectTransfer::class);
        $this->addAction('updateProjectTransferStatus', UpdateProjectTransferStatus::class);
    }
}
