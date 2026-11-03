<?php

namespace Utopia\Agents;

use Utopia\Agents\Parts\ToolCall;
use Utopia\Agents\Parts\ToolResult;

/**
 * The outcome of an agent run.
 */
final readonly class Result
{
    /**
     * Final text of the last step.
     */
    public string $text;

    /**
     * Why the last step ended.
     */
    public FinishReason $finish;

    /**
     * Usage summed over every step.
     */
    public Usage $usage;

    /**
     * @param  non-empty-list<Step>  $steps
     * @param  list<Message>  $messages  Messages the run produced, ready to append to the history
     * @param  mixed  $output  Decoded structured output when the agent has a schema
     */
    public function __construct(
        public array $steps,
        public array $messages,
        public mixed $output = null,
    ) {
        $last = $steps[\count($steps) - 1];
        $this->text = $last->text();
        $this->finish = $last->response->finish;
        $this->usage = array_reduce($steps, fn (Usage $usage, Step $step) => $usage->add($step->response->usage), new Usage());
    }

    /**
     * Tool calls of every step.
     *
     * @return list<ToolCall>
     */
    public function toolCalls(): array
    {
        return array_values(array_merge(...array_map(fn (Step $step) => $step->toolCalls(), $this->steps)));
    }

    /**
     * Tool results of every step.
     *
     * @return list<ToolResult>
     */
    public function toolResults(): array
    {
        return array_values(array_merge(...array_map(fn (Step $step) => $step->results, $this->steps)));
    }

    /**
     * Calls the agent did not run because their tools have no handler; the
     * caller answers them with Message::tool() and continues the conversation.
     *
     * @return list<ToolCall>
     */
    public function pending(): array
    {
        $last = $this->steps[\count($this->steps) - 1];
        $answered = array_map(fn (ToolResult $result) => $result->id, $last->results);

        return array_values(array_filter($last->toolCalls(), fn (ToolCall $call) => ! \in_array($call->id, $answered, true)));
    }
}
