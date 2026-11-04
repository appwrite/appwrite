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
use Appwrite\Platform\Modules\VCS\Http\Installations\Create as CreateInstallation;
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
        $this->addAction('getVCSGitHubAuthorize', GetGitHubAuthorize::class);
        $this->addAction('getVCSGitHubCallback', GetGitHubCallback::class);
        $this->addAction('updateExternalDeployment', UpdateExternalDeployment::class);

        // Gitea Authorization & Callback
        $this->addAction('getVCSGiteaAuthorize', GetGiteaAuthorize::class);
        $this->addAction('getVCSGiteaCallback', GetGiteaCallback::class);

        // GitLab Authorization & Callback
        $this->addAction('getVCSGitlabAuthorize', GetGitlabAuthorize::class);
        $this->addAction('getVCSGitlabCallback', GetGitlabCallback::class);

        // Bitbucket Authorization & Callback
        $this->addAction('getVCSBitbucketAuthorize', GetBitbucketAuthorize::class);
        $this->addAction('getVCSBitbucketCallback', GetBitbucketCallback::class);

        // Origin Authorization & Callback
        $this->addAction('getVCSOriginAuthorize', GetOriginAuthorize::class);
        $this->addAction('getVCSOriginCallback', GetOriginCallback::class);

        // Entire Authorization & Callback
        $this->addAction('getVCSEntireAuthorize', GetEntireAuthorize::class);
        $this->addAction('getVCSEntireCallback', GetEntireCallback::class);

        // Installations
        $this->addAction('createInstallation', CreateInstallation::class);
        $this->addAction('getInstallation', GetInstallation::class);
        $this->addAction('listInstallations', ListInstallations::class);
        $this->addAction('deleteInstallation', DeleteInstallation::class);
        $this->addAction('listNamespaces', ListNamespaces::class);

        // Repositories
        $this->addAction('createRepository', CreateRepository::class);
        $this->addAction('getRepository', GetRepository::class);
        $this->addAction('listRepositories', ListRepositories::class);
        $this->addAction('listRepositoryBranches', ListRepositoryBranches::class);
        $this->addAction('getRepositoryContents', GetRepositoryContents::class);
        $this->addAction('createRepositoryDetection', CreateRepositoryDetections::class);

        // Events
        $this->addAction('createVCSGitHubEvent', CreateGitHubEvent::class);
        $this->addAction('createVCSGiteaEvent', CreateGiteaEvent::class);
        $this->addAction('createVCSGitlabEvent', CreateGitlabEvent::class);
        $this->addAction('createVCSBitbucketEvent', CreateBitbucketEvent::class);
        $this->addAction('createVCSOriginEvent', CreateOriginEvent::class);
        $this->addAction('createVCSEntireEvent', CreateEntireEvent::class);
    }
}
