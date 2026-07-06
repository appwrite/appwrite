<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when a function is created (`functions.[functionId].create`).
 */
class FunctionCreated extends ResourceEvent
{
    public function __construct(Document $function, ?Document $project = null, ?Document $actor = null)
    {
        parent::__construct(
            event: 'functions.[functionId].create',
            params: ['functionId' => $function->getId()],
            document: $function,
            model: Response::MODEL_FUNCTION,
            project: $project,
            user: $actor,
        );
    }
}
