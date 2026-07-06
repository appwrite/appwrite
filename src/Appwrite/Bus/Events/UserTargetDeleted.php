<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when a user messaging target is deleted
 * (`users.[userId].targets.[targetId].delete`).
 */
class UserTargetDeleted extends ResourceEvent
{
    public function __construct(Document $target, string $userId, ?Document $project = null, ?Document $actor = null)
    {
        parent::__construct(
            event: 'users.[userId].targets.[targetId].delete',
            params: ['userId' => $userId, 'targetId' => $target->getId()],
            document: $target,
            model: Response::MODEL_TARGET,
            project: $project,
            user: $actor,
        );
    }
}
