<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when a team's preferences are updated (`teams.[teamId].update.prefs`).
 *
 * The broadcast payload is the preferences document (not the team), matching the
 * endpoint's response.
 */
class TeamPreferencesUpdated extends ResourceEvent
{
    public function __construct(string $teamId, Document $prefs, ?Document $project = null, ?Document $user = null)
    {
        parent::__construct(
            event: 'teams.[teamId].update.prefs',
            params: ['teamId' => $teamId],
            document: $prefs,
            model: Response::MODEL_PREFERENCES,
            project: $project,
            user: $user,
        );
    }
}
