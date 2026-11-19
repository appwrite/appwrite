<?php

namespace Utopia\Agents\Tests\Fakes;

use Utopia\Agents\Events\TextDelta;
use Utopia\Agents\Events\ToolCall as ToolCallEvent;
use Utopia\Agents\Model as Contract;
use Utopia\Agents\Parts\Text;
use Utopia\Agents\Parts\ToolCall;
use Utopia\Agents\Request;
use Utopia\Agents\Response;

/**
 * Answers each request with the next scripted response and records the
 * requests it received.
 */
class Model implements Contract
{
    /**
     * @var list<Request>
     */
    public array $requests = [];

    /**
     * @param  list<Response>  $responses
     */
    public function __construct(
        private array $responses = [],
    ) {
    }

    public function queue(Response $response): self
    {
        $this->responses[] = $response;

        return $this;
    }

    public function getName(): string
    {
        return 'fake';
    }

    public function getModel(): string
    {
        return 'fake-model';
    }

    public function generate(Request $request): Response
    {
        $this->requests[] = $request;
        $response = array_shift($this->responses);
        if ($response === null) {
            throw new \LogicException('No response queued for this request');
        }

        return $response;
    }

    public function stream(Request $request, callable $listener): Response
    {
        $response = $this->generate($request);

        foreach ($response->parts as $part) {
            if ($part instanceof Text) {
                $listener(new TextDelta($part->text));
            } elseif ($part instanceof ToolCall) {
                $listener(new ToolCallEvent($part));
            }
        }

        return $response;
    }
}
