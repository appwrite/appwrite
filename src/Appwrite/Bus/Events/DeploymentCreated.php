<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when a function deployment is created
 * (`functions.[functionId].deployments.[deploymentId].create`).
 */
class DeploymentCreated extends ResourceEvent
{
    public function __construct(Document $deployment, string $functionId, ?Document $project = null, ?Document $actor = null)
    {
        parent::__construct(
            event: 'functions.[functionId].deployments.[deploymentId].create',
            params: ['functionId' => $functionId, 'deploymentId' => $deployment->getId()],
            document: $deployment,
            model: Response::MODEL_DEPLOYMENT,
            project: $project,
            user: $actor,
        );
    }
}
