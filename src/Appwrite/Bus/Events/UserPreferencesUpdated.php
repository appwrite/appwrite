<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when a user's preferences are updated (`users.[userId].update.prefs`).
 *
 * The broadcast payload is the preferences document, matching the endpoint response.
 */
class UserPreferencesUpdated extends ResourceEvent
{
    public function __construct(string $userId, Document $prefs, ?Document $project = null, ?Document $actor = null)
    {
        parent::__construct(
            event: 'users.[userId].update.prefs',
            params: ['userId' => $userId],
            document: $prefs,
            model: Response::MODEL_PREFERENCES,
            project: $project,
            user: $actor,
        );
    }
}
