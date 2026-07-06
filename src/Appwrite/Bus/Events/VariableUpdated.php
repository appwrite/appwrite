<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when a variable is updated (`variables.[variableId].update`).
 *
 * Used by both function and site variable endpoints.
 */
class VariableUpdated extends ResourceEvent
{
    public function __construct(Document $variable, ?Document $project = null, ?Document $actor = null)
    {
        parent::__construct(
            event: 'variables.[variableId].update',
            params: ['variableId' => $variable->getId()],
            document: $variable,
            model: Response::MODEL_VARIABLE,
            project: $project,
            user: $actor,
        );
    }
}
