<?php

namespace Utopia\Agents;

use Utopia\Agents\Parts\ToolCall;

/**
 * What a model returned for one request.
 */
final readonly class Response
{
    /**
     * @param  list<Part>  $parts
     */
    public function __construct(
        public array $parts,
        public FinishReason $finish,
        public Usage $usage = new Usage(),
        public string $id = '',
        public string $model = '',
    ) {
    }

    /**
     * The response as an assistant message for the conversation history.
     */
    public function message(): Message
    {
        return Message::assistant($this->parts);
    }

    public function text(): string
    {
        return $this->message()->text();
    }

    /**
     * @return list<ToolCall>
     */
    public function toolCalls(): array
    {
        return $this->message()->toolCalls();
    }
}
