<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when a site log is deleted (`sites.[siteId].logs.[logId].delete`).
 */
class SiteLogDeleted extends ResourceEvent
{
    public function __construct(Document $log, string $siteId, ?Document $project = null, ?Document $actor = null)
    {
        parent::__construct(
            event: 'sites.[siteId].logs.[logId].delete',
            params: ['siteId' => $siteId, 'logId' => $log->getId()],
            document: $log,
            model: Response::MODEL_EXECUTION,
            project: $project,
            user: $actor,
        );
    }
}
