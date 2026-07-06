<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when a user messaging target is updated
 * (`users.[userId].targets.[targetId].update`).
 */
class UserTargetUpdated extends ResourceEvent
{
    public function __construct(Document $target, string $userId, ?Document $project = null, ?Document $actor = null)
    {
        parent::__construct(
            event: 'users.[userId].targets.[targetId].update',
            params: ['userId' => $userId, 'targetId' => $target->getId()],
            document: $target,
            model: Response::MODEL_TARGET,
            project: $project,
            user: $actor,
        );
    }
}
