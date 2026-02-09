<?php

namespace Appwrite\Platform\Modules\VCS\Services;

use Appwrite\Platform\Modules\VCS\Http\Authorization\Get as GetAuthorization;
use Appwrite\Platform\Modules\VCS\Http\Callback\Get as GetCallback;
use Appwrite\Platform\Modules\VCS\Http\Installations\Delete as DeleteInstallation;
use Appwrite\Platform\Modules\VCS\Http\Installations\Get as GetInstallation;
use Appwrite\Platform\Modules\VCS\Http\Installations\Repositories\Branches\XList as ListRepositoryBranches;
use Appwrite\Platform\Modules\VCS\Http\Installations\Repositories\Contents\Get as GetRepositoryContents;
use Appwrite\Platform\Modules\VCS\Http\Installations\Repositories\Create as CreateRepository;
use Appwrite\Platform\Modules\VCS\Http\Installations\Repositories\Detections\Create as CreateRepositoryDetections;
use Appwrite\Platform\Modules\VCS\Http\Installations\Repositories\Get as GetRepository;
use Appwrite\Platform\Modules\VCS\Http\Installations\Repositories\XList as ListRepositories;
use Appwrite\Platform\Modules\VCS\Http\Installations\XList as ListInstallations;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        // Authorization & Callback
        $this->addAction(GetAuthorization::getName(), new GetAuthorization());
        $this->addAction(GetCallback::getName(), new GetCallback());

        // Installations
        $this->addAction(GetInstallation::getName(), new GetInstallation());
        $this->addAction(ListInstallations::getName(), new ListInstallations());
        $this->addAction(DeleteInstallation::getName(), new DeleteInstallation());

        // Repositories
        $this->addAction(CreateRepository::getName(), new CreateRepository());
        $this->addAction(GetRepository::getName(), new GetRepository());
        $this->addAction(ListRepositories::getName(), new ListRepositories());
        $this->addAction(ListRepositoryBranches::getName(), new ListRepositoryBranches());
        $this->addAction(GetRepositoryContents::getName(), new GetRepositoryContents());
        $this->addAction(CreateRepositoryDetections::getName(), new CreateRepositoryDetections());
    }
}
