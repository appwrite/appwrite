<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when a team's name is updated (`teams.[teamId].update`).
 */
class TeamUpdated extends ResourceEvent
{
    public function __construct(Document $team, ?Document $project = null, ?Document $user = null)
    {
        parent::__construct(
            event: 'teams.[teamId].update',
            params: ['teamId' => $team->getId()],
            document: $team,
            model: Response::MODEL_TEAM,
            project: $project,
            user: $user,
        );
    }
}
