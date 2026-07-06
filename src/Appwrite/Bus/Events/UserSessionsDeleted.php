<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when all of a user's sessions are deleted (`users.[userId].sessions.delete`).
 */
class UserSessionsDeleted extends ResourceEvent
{
    public function __construct(Document $user, ?Document $project = null, ?Document $actor = null)
    {
        parent::__construct(
            event: 'users.[userId].sessions.delete',
            params: ['userId' => $user->getId()],
            document: $user,
            model: Response::MODEL_USER,
            project: $project,
            user: $actor,
        );
    }
}
