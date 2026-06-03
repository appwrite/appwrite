<?php

namespace Appwrite\Platform\Modules\Deployments\Services;

use Appwrite\Platform\Modules\Deployments\Http\Deployments\Artifacts\Build\Update as UpdateBuildArtifact;
use Appwrite\Platform\Modules\Deployments\Http\Deployments\Artifacts\Source\Get as GetSourceArtifact;
use Appwrite\Platform\Modules\Deployments\Http\Deployments\Events\Create as CreateDeploymentEvent;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        $this->addAction(GetSourceArtifact::getName(), new GetSourceArtifact());
        $this->addAction(UpdateBuildArtifact::getName(), new UpdateBuildArtifact());

        $this->addAction(CreateDeploymentEvent::getName(), new CreateDeploymentEvent());
    }
}
