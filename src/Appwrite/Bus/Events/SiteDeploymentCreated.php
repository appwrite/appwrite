<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when a site deployment is created
 * (`sites.[siteId].deployments.[deploymentId].create`).
 */
class SiteDeploymentCreated extends ResourceEvent
{
    public function __construct(Document $deployment, string $siteId, ?Document $project = null, ?Document $actor = null)
    {
        parent::__construct(
            event: 'sites.[siteId].deployments.[deploymentId].create',
            params: ['siteId' => $siteId, 'deploymentId' => $deployment->getId()],
            document: $deployment,
            model: Response::MODEL_DEPLOYMENT,
            project: $project,
            user: $actor,
        );
    }
}
