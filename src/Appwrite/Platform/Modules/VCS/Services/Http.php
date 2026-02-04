<?php

namespace Appwrite\Platform\Modules\VCS\Services;

use Appwrite\Platform\Modules\VCS\Http\Installations\Delete as DeleteInstallation;
use Appwrite\Platform\Modules\VCS\Http\Installations\Get as GetInstallation;
use Appwrite\Platform\Modules\VCS\Http\Installations\Repositories\Branches\XList as ListRepositoryBranches;
use Appwrite\Platform\Modules\VCS\Http\Installations\Repositories\Contents\Get as GetRepositoryContents;
use Appwrite\Platform\Modules\VCS\Http\Installations\Repositories\Create as CreateRepository;
use Appwrite\Platform\Modules\VCS\Http\Installations\Repositories\Detection\Create as CreateRepositoryDetection;
use Appwrite\Platform\Modules\VCS\Http\Installations\Repositories\Get as GetRepository;
use Appwrite\Platform\Modules\VCS\Http\Installations\Repositories\XList as ListRepositories;
use Appwrite\Platform\Modules\VCS\Http\Installations\XList as ListInstallations;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

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
        $this->addAction(CreateRepositoryDetection::getName(), new CreateRepositoryDetection());
    }
}
