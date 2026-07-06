<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when a site's active deployment is changed
 * (`sites.[siteId].deployments.[deploymentId].update`).
 *
 * The endpoint responds with the site, so the payload is the site
 * (distinct from {@see SiteDeploymentUpdated}, which carries the deployment).
 */
class SiteActiveDeploymentUpdated extends ResourceEvent
{
    public function __construct(Document $site, string $deploymentId, ?Document $project = null, ?Document $actor = null)
    {
        parent::__construct(
            event: 'sites.[siteId].deployments.[deploymentId].update',
            params: ['siteId' => $site->getId(), 'deploymentId' => $deploymentId],
            document: $site,
            model: Response::MODEL_SITE,
            project: $project,
            user: $actor,
        );
    }
}
