<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when a user is deleted (`users.[userId].delete`).
 */
class UserDeleted extends ResourceEvent
{
    public function __construct(Document $user, ?Document $project = null, ?Document $actor = null)
    {
        parent::__construct(
            event: 'users.[userId].delete',
            params: ['userId' => $user->getId()],
            document: $user,
            model: Response::MODEL_USER,
            project: $project,
            user: $actor,
        );
    }
}
