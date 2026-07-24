<?php

namespace Appwrite\Platform\Modules\VCS\Services;

use Appwrite\Platform\Modules\VCS\Http\Gitea\Authorize\Get as GetGiteaAuthorize;
use Appwrite\Platform\Modules\VCS\Http\Gitea\Callback\Get as GetGiteaCallback;
use Appwrite\Platform\Modules\VCS\Http\Gitea\Events\Create as CreateGiteaEvent;
use Appwrite\Platform\Modules\VCS\Http\GitHub\Authorize\External\Update as UpdateExternalDeployment;
use Appwrite\Platform\Modules\VCS\Http\GitHub\Authorize\Get as GetGitHubAuthorize;
use Appwrite\Platform\Modules\VCS\Http\GitHub\Callback\Get as GetGitHubCallback;
use Appwrite\Platform\Modules\VCS\Http\GitHub\Events\Create as CreateGitHubEvent;
use Appwrite\Platform\Modules\VCS\Http\Gitlab\Authorize\Get as GetGitlabAuthorize;
use Appwrite\Platform\Modules\VCS\Http\Gitlab\Callback\Get as GetGitlabCallback;
use Appwrite\Platform\Modules\VCS\Http\Gitlab\Events\Create as CreateGitlabEvent;
use Appwrite\Platform\Modules\VCS\Http\Installations\Delete as DeleteInstallation;
use Appwrite\Platform\Modules\VCS\Http\Installations\Get as GetInstallation;
use Appwrite\Platform\Modules\VCS\Http\Installations\Namespaces\XList as ListNamespaces;
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

        // GitHub Authorization & Callback
        $this->addAction(GetGitHubAuthorize::getName(), new GetGitHubAuthorize());
        $this->addAction(GetGitHubCallback::getName(), new GetGitHubCallback());
        $this->addAction(UpdateExternalDeployment::getName(), new UpdateExternalDeployment());

        // Gitea Authorization & Callback
        $this->addAction(GetGiteaAuthorize::getName(), new GetGiteaAuthorize());
        $this->addAction(GetGiteaCallback::getName(), new GetGiteaCallback());

        // GitLab Authorization & Callback
        $this->addAction(GetGitlabAuthorize::getName(), new GetGitlabAuthorize());
        $this->addAction(GetGitlabCallback::getName(), new GetGitlabCallback());

        // Installations
        $this->addAction(GetInstallation::getName(), new GetInstallation());
        $this->addAction(ListInstallations::getName(), new ListInstallations());
        $this->addAction(DeleteInstallation::getName(), new DeleteInstallation());
        $this->addAction(ListNamespaces::getName(), new ListNamespaces());

        // Repositories
        $this->addAction(CreateRepository::getName(), new CreateRepository());
        $this->addAction(GetRepository::getName(), new GetRepository());
        $this->addAction(ListRepositories::getName(), new ListRepositories());
        $this->addAction(ListRepositoryBranches::getName(), new ListRepositoryBranches());
        $this->addAction(GetRepositoryContents::getName(), new GetRepositoryContents());
        $this->addAction(CreateRepositoryDetections::getName(), new CreateRepositoryDetections());

        // Events
        $this->addAction(CreateGitHubEvent::getName(), new CreateGitHubEvent());
        $this->addAction(CreateGiteaEvent::getName(), new CreateGiteaEvent());
        $this->addAction(CreateGitlabEvent::getName(), new CreateGitlabEvent());
    }
}
