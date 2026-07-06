<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when a team membership's roles are updated
 * (`teams.[teamId].memberships.[membershipId].update`).
 *
 * The `userId` param is the member being updated, not the acting user.
 */
class MembershipUpdated extends ResourceEvent
{
    public function __construct(Document $membership, string $teamId, string $userId, ?Document $project = null, ?Document $user = null)
    {
        parent::__construct(
            event: 'teams.[teamId].memberships.[membershipId].update',
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
