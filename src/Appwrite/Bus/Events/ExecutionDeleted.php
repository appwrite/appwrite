<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when a function execution is deleted
 * (`functions.[functionId].executions.[executionId].delete`).
 */
class ExecutionDeleted extends ResourceEvent
{
    public function __construct(Document $execution, string $functionId, ?Document $project = null, ?Document $actor = null)
    {
        parent::__construct(
            event: 'functions.[functionId].executions.[executionId].delete',
            params: ['functionId' => $functionId, 'executionId' => $execution->getId()],
            document: $execution,
            model: Response::MODEL_EXECUTION,
            project: $project,
            user: $actor,
        );
    }
}
