<?php

namespace Utopia\Agents;

use Utopia\Agents\Parts\ToolCall;
use Utopia\Agents\Parts\ToolResult;

/**
 * One round of an agent run: a model response and the tool results it led to.
 */
final readonly class Step
{
    /**
     * @param  list<ToolResult>  $results
     */
    public function __construct(
        public Response $response,
        public array $results = [],
    ) {
    }

    public function text(): string
    {
        return $this->response->text();
    }

    /**
     * @return list<ToolCall>
     */
    public function toolCalls(): array
    {
        return $this->response->toolCalls();
    }
}
