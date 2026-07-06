<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when a site is created (`sites.[siteId].create`).
 */
class SiteCreated extends ResourceEvent
{
    public function __construct(Document $site, ?Document $project = null, ?Document $actor = null)
    {
        parent::__construct(
            event: 'sites.[siteId].create',
            params: ['siteId' => $site->getId()],
            document: $site,
            model: Response::MODEL_SITE,
            project: $project,
            user: $actor,
        );
    }
}
