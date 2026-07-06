<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when a function's active deployment is changed
 * (`functions.[functionId].deployments.[deploymentId].update`).
 *
 * The endpoint responds with the function, so the payload is the function
 * (distinct from {@see DeploymentUpdated}, which carries the deployment).
 */
class FunctionDeploymentUpdated extends ResourceEvent
{
    public function __construct(Document $function, string $deploymentId, ?Document $project = null, ?Document $actor = null)
    {
        parent::__construct(
            event: 'functions.[functionId].deployments.[deploymentId].update',
            params: ['functionId' => $function->getId(), 'deploymentId' => $deploymentId],
            document: $function,
            model: Response::MODEL_FUNCTION,
            project: $project,
            user: $actor,
        );
    }
}
