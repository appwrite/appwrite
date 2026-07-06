<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when a team membership is deleted
 * (`teams.[teamId].memberships.[membershipId].delete`).
 *
 * The `userId` param is the removed member, not the acting user.
 */
class MembershipDeleted extends ResourceEvent
{
    public function __construct(Document $membership, string $teamId, string $userId, ?Document $project = null, ?Document $user = null)
    {
        parent::__construct(
            event: 'teams.[teamId].memberships.[membershipId].delete',
            params: [
                'teamId' => $teamId,
                'userId' => $userId,
                'membershipId' => $membership->getId(),
            ],
            document: $membership,
            model: Response::MODEL_MEMBERSHIP,
            project: $project,
            user: $user,
        );
    }
}
