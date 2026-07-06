<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when a team is deleted (`teams.[teamId].delete`).
 */
class TeamDeleted extends ResourceEvent
{
    public function __construct(Document $team, ?Document $project = null, ?Document $user = null)
    {
        parent::__construct(
            event: 'teams.[teamId].delete',
            params: ['teamId' => $team->getId()],
            document: $team,
            model: Response::MODEL_TEAM,
            project: $project,
            user: $user,
        );
    }
}
