<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when a function deployment is deleted
 * (`functions.[functionId].deployments.[deploymentId].delete`).
 */
class DeploymentDeleted extends ResourceEvent
{
    public function __construct(Document $deployment, string $functionId, ?Document $project = null, ?Document $actor = null)
    {
        parent::__construct(
            event: 'functions.[functionId].deployments.[deploymentId].delete',
            params: ['functionId' => $functionId, 'deploymentId' => $deployment->getId()],
            document: $deployment,
            model: Response::MODEL_DEPLOYMENT,
            project: $project,
            user: $actor,
        );
    }
}
