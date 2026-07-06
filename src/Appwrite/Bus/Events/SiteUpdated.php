<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when a site is updated (`sites.[siteId].update`).
 */
class SiteUpdated extends ResourceEvent
{
    public function __construct(Document $site, ?Document $project = null, ?Document $actor = null)
    {
        parent::__construct(
            event: 'sites.[siteId].update',
            params: ['siteId' => $site->getId()],
            document: $site,
            model: Response::MODEL_SITE,
            project: $project,
            user: $actor,
        );
    }
}
