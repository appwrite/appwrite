<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when a variable is deleted (`variables.[variableId].delete`).
 *
 * Used by both function and site variable endpoints.
 */
class VariableDeleted extends ResourceEvent
{
    public function __construct(Document $variable, ?Document $project = null, ?Document $actor = null)
    {
        parent::__construct(
            event: 'variables.[variableId].delete',
            params: ['variableId' => $variable->getId()],
            document: $variable,
            model: Response::MODEL_VARIABLE,
            project: $project,
            user: $actor,
        );
    }
}
