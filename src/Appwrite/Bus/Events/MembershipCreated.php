<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when a team membership is created
 * (`teams.[teamId].memberships.[membershipId].create`).
 *
 * The `userId` param is the invited member, not the acting user.
 */
class MembershipCreated extends ResourceEvent
{
    public function __construct(Document $membership, string $teamId, string $userId, ?Document $project = null, ?Document $user = null)
    {
        parent::__construct(
            event: 'teams.[teamId].memberships.[membershipId].create',
            params: [
                'userId' => $userId,
                'teamId' => $teamId,
                'membershipId' => $membership->getId(),
            ],
            document: $membership,
            model: Response::MODEL_MEMBERSHIP,
            project: $project,
            user: $user,
        );
    }
}
