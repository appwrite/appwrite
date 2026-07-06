<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when a user identity is deleted
 * (`users.[userId].identities.[identityId].delete`).
 */
class UserIdentityDeleted extends ResourceEvent
{
    public function __construct(Document $identity, string $userId, ?Document $project = null, ?Document $actor = null)
    {
        parent::__construct(
            event: 'users.[userId].identities.[identityId].delete',
            params: ['userId' => $userId, 'identityId' => $identity->getId()],
            document: $identity,
            model: Response::MODEL_IDENTITY,
            project: $project,
            user: $actor,
        );
    }
}
