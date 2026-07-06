<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when a team is created (`teams.[teamId].create`).
 */
class TeamCreated extends ResourceEvent
{
    public function __construct(Document $team, ?Document $project = null, ?Document $user = null)
    {
        parent::__construct(
            event: 'teams.[teamId].create',
            params: \array_filter([
                'teamId' => $team->getId(),
                'userId' => $user?->getId(),
            ]),
            document: $team,
            model: Response::MODEL_TEAM,
            project: $project,
            user: $user,
        );
    }
}
