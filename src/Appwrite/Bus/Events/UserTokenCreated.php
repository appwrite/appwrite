<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when a token is created for a user
 * (`users.[userId].tokens.[tokenId].create`).
 */
class UserTokenCreated extends ResourceEvent
{
    public function __construct(Document $token, string $userId, ?Document $project = null, ?Document $actor = null)
    {
        parent::__construct(
            event: 'users.[userId].tokens.[tokenId].create',
            params: ['userId' => $userId, 'tokenId' => $token->getId()],
            document: $token,
            model: Response::MODEL_TOKEN,
            project: $project,
            user: $actor,
        );
    }
}
