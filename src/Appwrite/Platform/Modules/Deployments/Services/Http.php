<?php

namespace Appwrite\Platform\Modules\Deployments\Services;

use Appwrite\Platform\Modules\Deployments\Http\Deployments\Artifacts\Build\Update as UpdateBuildArtifact;
use Appwrite\Platform\Modules\Deployments\Http\Deployments\Artifacts\Source\Get as GetSourceArtifact;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        // Deployment artifacts
        $this->addAction(GetSourceArtifact::getName(), new GetSourceArtifact());
        $this->addAction(UpdateBuildArtifact::getName(), new UpdateBuildArtifact());
    }
}
