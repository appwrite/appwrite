<?php

namespace Utopia\Agents;

/**
 * A running conversation with an agent: keeps the message history and the
 * usage of every run.
 */
class Conversation
{
    protected Usage $usage;

    /**
     * @param  list<Message>  $messages  Earlier history to continue from
     */
    public function __construct(
        protected Agent $agent,
        protected array $messages = [],
    ) {
        $this->usage = new Usage();
    }

    /**
     * Append messages without running the agent, for example tool results
     * for calls the agent left pending.
     */
    public function add(Message ...$messages): static
    {
        foreach ($messages as $message) {
            $this->messages[] = $message;
        }

        return $this;
    }

    /**
     * Append $input, run the agent on the whole history and append what it
     * produced. Pass no input to continue after add().
     *
     * @param  string|Message|list<Message>  $input
     * @param  (callable(Event): void)|null  $listener
     *
     * @throws Exception
     */
    public function send(string|Message|array $input = [], ?callable $listener = null): Result
    {
        $input = match (true) {
            \is_string($input) => [Message::user($input)],
            $input instanceof Message => [$input],
            default => $input,
        };
        $this->add(...$input);

        $result = $listener === null
            ? $this->agent->generate($this->messages)
            : $this->agent->stream($this->messages, $listener);

        $this->add(...$result->messages);
        $this->usage = $this->usage->add($result->usage);

        return $result;
    }

    /**
     * @return list<Message>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * Usage summed over every run of this conversation.
     */
    public function getUsage(): Usage
    {
        return $this->usage;
    }

    public function getAgent(): Agent
    {
        return $this->agent;
    }
}
