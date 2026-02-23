<?php

namespace Appwrite\Bus;

use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Bus\Event;

class RequestCompleted implements Event
{
    /**
     * @param array<string, mixed> $project
     */
    public function __construct(
        public readonly array $project,
        public readonly Request $request,
        public readonly Response $response,
    ) {
    }
}
