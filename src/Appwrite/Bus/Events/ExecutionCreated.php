<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when a function execution is created
 * (`functions.[functionId].executions.[executionId].create`).
 */
class ExecutionCreated extends ResourceEvent
{
    public function __construct(Document $execution, Document $function, ?Document $project = null, ?Document $actor = null)
    {
        parent::__construct(
            event: 'functions.[functionId].executions.[executionId].create',
            params: ['functionId' => $function->getId(), 'executionId' => $execution->getId()],
            document: $execution,
            model: Response::MODEL_EXECUTION,
            project: $project,
            user: $actor,
            context: ['function' => $function],
        );
    }
}
