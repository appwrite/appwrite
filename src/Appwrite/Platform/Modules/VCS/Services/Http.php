<?php

namespace Appwrite\Platform\Modules\VCS\Services;

use Appwrite\Platform\Modules\VCS\Http\Bitbucket\Authorize\Get as GetBitbucketAuthorize;
use Appwrite\Platform\Modules\VCS\Http\Bitbucket\Callback\Get as GetBitbucketCallback;
use Appwrite\Platform\Modules\VCS\Http\Bitbucket\Events\Create as CreateBitbucketEvent;
use Appwrite\Platform\Modules\VCS\Http\Entire\Authorize\Get as GetEntireAuthorize;
use Appwrite\Platform\Modules\VCS\Http\Entire\Callback\Get as GetEntireCallback;
use Appwrite\Platform\Modules\VCS\Http\Entire\Events\Create as CreateEntireEvent;
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
use Appwrite\Platform\Modules\VCS\Http\Origin\Authorize\Get as GetOriginAuthorize;
use Appwrite\Platform\Modules\VCS\Http\Origin\Callback\Get as GetOriginCallback;
use Appwrite\Platform\Modules\VCS\Http\Origin\Events\Create as CreateOriginEvent;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        // GitHub Authorization & Callback
        $this->addAction(GetGitHubAuthorize::getName(), GetGitHubAuthorize::class);
        $this->addAction(GetGitHubCallback::getName(), GetGitHubCallback::class);
        $this->addAction(UpdateExternalDeployment::getName(), UpdateExternalDeployment::class);

        // Gitea Authorization & Callback
        $this->addAction(GetGiteaAuthorize::getName(), GetGiteaAuthorize::class);
        $this->addAction(GetGiteaCallback::getName(), GetGiteaCallback::class);

        // GitLab Authorization & Callback
        $this->addAction(GetGitlabAuthorize::getName(), GetGitlabAuthorize::class);
        $this->addAction(GetGitlabCallback::getName(), GetGitlabCallback::class);

        // Bitbucket Authorization & Callback
        $this->addAction(GetBitbucketAuthorize::getName(), GetBitbucketAuthorize::class);
        $this->addAction(GetBitbucketCallback::getName(), GetBitbucketCallback::class);

        // Origin Authorization & Callback
        $this->addAction(GetOriginAuthorize::getName(), GetOriginAuthorize::class);
        $this->addAction(GetOriginCallback::getName(), GetOriginCallback::class);

        // Entire Authorization & Callback
        $this->addAction(GetEntireAuthorize::getName(), GetEntireAuthorize::class);
        $this->addAction(GetEntireCallback::getName(), GetEntireCallback::class);

        // Installations
        $this->addAction(GetInstallation::getName(), GetInstallation::class);
        $this->addAction(ListInstallations::getName(), ListInstallations::class);
        $this->addAction(DeleteInstallation::getName(), DeleteInstallation::class);
        $this->addAction(ListNamespaces::getName(), ListNamespaces::class);

        // Repositories
        $this->addAction(CreateRepository::getName(), CreateRepository::class);
        $this->addAction(GetRepository::getName(), GetRepository::class);
        $this->addAction(ListRepositories::getName(), ListRepositories::class);
        $this->addAction(ListRepositoryBranches::getName(), ListRepositoryBranches::class);
        $this->addAction(GetRepositoryContents::getName(), GetRepositoryContents::class);
        $this->addAction(CreateRepositoryDetections::getName(), CreateRepositoryDetections::class);

        // Events
        $this->addAction(CreateGitHubEvent::getName(), CreateGitHubEvent::class);
        $this->addAction(CreateGiteaEvent::getName(), CreateGiteaEvent::class);
        $this->addAction(CreateGitlabEvent::getName(), CreateGitlabEvent::class);
        $this->addAction(CreateBitbucketEvent::getName(), CreateBitbucketEvent::class);
        $this->addAction(CreateOriginEvent::getName(), CreateOriginEvent::class);
        $this->addAction(CreateEntireEvent::getName(), CreateEntireEvent::class);
    }
}
