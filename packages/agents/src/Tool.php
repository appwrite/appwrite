<?php

namespace Utopia\Agents;

use Utopia\Agents\Parts\ToolCall;

/**
 * A function the model can call.
 *
 * With a handler the agent runs the tool and feeds the result back to the
 * model. Without one the tool is client-side: the agent stops and returns
 * the call, and the caller answers it with Message::tool().
 */
final readonly class Tool
{
    /**
     * @param  (\Closure(array<string, mixed>, ToolCall): mixed)|null  $handler
     */
    public function __construct(
        public string $name,
        public string $description,
        public Schema $input,
        public ?\Closure $handler = null,
    ) {
        if ($name === '') {
            throw new \InvalidArgumentException('A tool needs a name');
        }
    }

    /**
     * Run the handler with the call's arguments.
     */
    public function execute(ToolCall $call): mixed
    {
        if ($this->handler === null) {
            throw new \LogicException("Tool '{$this->name}' has no handler");
        }

        return ($this->handler)($call->arguments, $call);
    }
}
