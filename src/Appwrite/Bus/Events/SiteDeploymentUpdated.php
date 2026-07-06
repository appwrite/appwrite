<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when a site deployment is updated — currently emitted by the
 * duplicate-deployment endpoint (`sites.[siteId].deployments.[deploymentId].update`).
 *
 * Carries the deployment (distinct from {@see SiteActiveDeploymentUpdated}, which
 * carries the site).
 */
class SiteDeploymentUpdated extends ResourceEvent
{
    public function __construct(Document $deployment, string $siteId, ?Document $project = null, ?Document $actor = null)
    {
        parent::__construct(
            event: 'sites.[siteId].deployments.[deploymentId].update',
            params: ['siteId' => $siteId, 'deploymentId' => $deployment->getId()],
            document: $deployment,
            model: Response::MODEL_DEPLOYMENT,
            project: $project,
            user: $actor,
        );
    }
}
