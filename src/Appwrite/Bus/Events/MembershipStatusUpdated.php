<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when a team membership is confirmed via its status endpoint
 * (`teams.[teamId].memberships.[membershipId].update.status`).
 *
 * The `userId` param is the member accepting the invite, not necessarily an
 * authenticated actor.
 */
class MembershipStatusUpdated extends ResourceEvent
{
    public function __construct(Document $membership, string $teamId, string $userId, ?Document $project = null, ?Document $user = null)
    {
        parent::__construct(
            event: 'teams.[teamId].memberships.[membershipId].update.status',
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
