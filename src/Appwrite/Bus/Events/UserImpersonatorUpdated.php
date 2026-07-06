<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when a user's impersonator capability is updated
 * (`users.[userId].update.impersonator`).
 */
class UserImpersonatorUpdated extends ResourceEvent
{
    public function __construct(Document $user, ?Document $project = null, ?Document $actor = null)
    {
        parent::__construct(
            event: 'users.[userId].update.impersonator',
            params: ['userId' => $user->getId()],
            document: $user,
            model: Response::MODEL_USER,
            project: $project,
            user: $actor,
        );
    }
}
