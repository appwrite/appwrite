<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when a function is deleted (`functions.[functionId].delete`).
 */
class FunctionDeleted extends ResourceEvent
{
    public function __construct(Document $function, ?Document $project = null, ?Document $actor = null)
    {
        parent::__construct(
            event: 'functions.[functionId].delete',
            params: ['functionId' => $function->getId()],
            document: $function,
            model: Response::MODEL_FUNCTION,
            project: $project,
            user: $actor,
        );
    }
}
