<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when a session is created for a user
 * (`users.[userId].sessions.[sessionId].create`).
 */
class UserSessionCreated extends ResourceEvent
{
    public function __construct(Document $session, string $userId, ?Document $project = null, ?Document $actor = null)
    {
        parent::__construct(
            event: 'users.[userId].sessions.[sessionId].create',
            params: ['userId' => $userId, 'sessionId' => $session->getId()],
            document: $session,
            model: Response::MODEL_SESSION,
            project: $project,
            user: $actor,
        );
    }
}
