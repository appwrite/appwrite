<?php

namespace Utopia\Agents\Adapters;

use Psr\Http\Client\ClientInterface;
use Utopia\Agents\Adapter;
use Utopia\Agents\Event;
use Utopia\Agents\Events\ReasoningDelta;
use Utopia\Agents\Events\TextDelta;
use Utopia\Agents\Events\ToolCall as ToolCallEvent;
use Utopia\Agents\Events\ToolInputDelta;
use Utopia\Agents\Exception\Api;
use Utopia\Agents\Exception\Unsupported;
use Utopia\Agents\FinishReason;
use Utopia\Agents\Message;
use Utopia\Agents\Model;
use Utopia\Agents\Part;
use Utopia\Agents\Parts\File;
use Utopia\Agents\Parts\Image;
use Utopia\Agents\Parts\Reasoning;
use Utopia\Agents\Parts\Text;
use Utopia\Agents\Parts\ToolCall;
use Utopia\Agents\Request;
use Utopia\Agents\Response;
use Utopia\Agents\Role;
use Utopia\Agents\Tool;
use Utopia\Agents\ToolChoice;
use Utopia\Agents\Usage;
use Utopia\Client\Psr18\StreamingClientInterface;

/**
 * OpenAI Chat Completions, and the providers that speak the same protocol.
 *
 * Streams every request with usage reporting, assembles tool calls from their
 * deltas and reads reasoning from the "reasoning_content" or "reasoning"
 * delta fields that compatible providers use.
 */
class OpenAI extends Adapter implements Model
{
    protected const string ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    protected string $endpoint;

    public function __construct(
        protected string $apiKey,
        protected string $model,
        ?string $endpoint = null,
        int $timeout = 90000,
        (ClientInterface&StreamingClientInterface)|null $client = null,
    ) {
        $this->endpoint = $endpoint ?? static::ENDPOINT;
        $this->timeout = $timeout;
        $this->client = $client;
    }

    public function getName(): string
    {
        return 'openai';
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function generate(Request $request): Response
    {
        return $this->stream($request, static function (Event $event): void {
        });
    }

    public function stream(Request $request, callable $listener): Response
    {
        $text = '';
        $reasoning = '';
        /** @var array<int, array{id: string, name: string, arguments: string}> $calls */
        $calls = [];
        /** @var FinishReason|null $finish */
        $finish = null;
        $usage = new Usage();
        $id = '';
        $model = $this->model;

        $this->events($this->endpoint, $this->payload($request), function (string $name, array $chunk) use (&$text, &$reasoning, &$calls, &$finish, &$usage, &$id, &$model, $listener): void {
            if (isset($chunk['error'])) {
                [$type, $message] = $this->describe(json_encode($chunk) ?: '');
                throw new Api(ucfirst($this->getName())." API error ({$type}): {$message}", $this->getName(), type: $type);
            }

            if (isset($chunk['id']) && \is_string($chunk['id'])) {
                $id = $chunk['id'];
            }
            if (isset($chunk['model']) && \is_string($chunk['model'])) {
                $model = $chunk['model'];
            }
            if (isset($chunk['usage']) && \is_array($chunk['usage'])) {
                $usage = $this->usage($chunk['usage']);
            }

            $choices = \is_array($chunk['choices'] ?? null) ? $chunk['choices'] : [];
            $choice = $choices[0] ?? null;
            if (! \is_array($choice)) {
                return;
            }

            $delta = \is_array($choice['delta'] ?? null) ? $choice['delta'] : [];

            foreach (['reasoning_content', 'reasoning'] as $key) {
                if (isset($delta[$key]) && \is_string($delta[$key]) && $delta[$key] !== '') {
                    $reasoning .= $delta[$key];
                    $listener(new ReasoningDelta($delta[$key]));
                    break;
                }
            }

            if (isset($delta['content']) && \is_string($delta['content']) && $delta['content'] !== '') {
                $text .= $delta['content'];
                $listener(new TextDelta($delta['content']));
            }

            $toolCalls = \is_array($delta['tool_calls'] ?? null) ? $delta['tool_calls'] : [];
            foreach ($toolCalls as $toolCall) {
                if (! \is_array($toolCall)) {
                    continue;
                }
                $index = \is_int($toolCall['index'] ?? null) ? $toolCall['index'] : \count($calls);
                $calls[$index] ??= ['id' => '', 'name' => '', 'arguments' => ''];
                if (isset($toolCall['id']) && \is_string($toolCall['id']) && $toolCall['id'] !== '') {
                    $calls[$index]['id'] = $toolCall['id'];
                }
                $function = \is_array($toolCall['function'] ?? null) ? $toolCall['function'] : [];
                if (isset($function['name']) && \is_string($function['name']) && $function['name'] !== '') {
                    $calls[$index]['name'] = $function['name'];
                }
                if (isset($function['arguments']) && \is_string($function['arguments']) && $function['arguments'] !== '') {
                    $calls[$index]['arguments'] .= $function['arguments'];
                    $listener(new ToolInputDelta($calls[$index]['id'], $calls[$index]['name'], $function['arguments']));
                }
            }

            if (isset($choice['finish_reason']) && \is_string($choice['finish_reason'])) {
                $finish = $this->finish($choice['finish_reason']);
            }
        });

        $parts = [];
        if ($reasoning !== '') {
            $parts[] = new Reasoning($reasoning);
        }
        if ($text !== '') {
            $parts[] = new Text($text);
        }

        ksort($calls);
        foreach ($calls as $index => $call) {
            $part = new ToolCall($call['id'] !== '' ? $call['id'] : 'call_'.$index, $call['name'], $this->arguments($call['arguments']));
            $parts[] = $part;
            $listener(new ToolCallEvent($part));
        }

        if ($calls !== [] && ($finish === null || $finish === FinishReason::Stop)) {
            $finish = FinishReason::ToolCalls;
        }

        return new Response($parts, $finish ?? FinishReason::Other, $usage, $id, $model);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(Request $request): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => $this->messages($request->messages),
            'stream' => true,
            'stream_options' => ['include_usage' => true],
        ];

        if ($request->tools !== []) {
            $payload['tools'] = array_map(fn (Tool $tool) => [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name,
                    'description' => $tool->description,
                    'parameters' => $tool->input->toArray(),
                ],
            ], $request->tools);
        }

        if ($request->toolChoice !== null) {
            $payload['tool_choice'] = match ($request->toolChoice->type) {
                ToolChoice::TOOL => ['type' => 'function', 'function' => ['name' => $request->toolChoice->name]],
                default => $request->toolChoice->type,
            };
        }

        if ($request->schema !== null) {
            $payload['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'response',
                    'strict' => true,
                    'schema' => $request->schema->toArray(),
                ],
            ];
        }

        $settings = [
            $this->tokens() => $request->maxTokens,
            'temperature' => $request->temperature,
            'top_p' => $request->topP,
            'seed' => $request->seed,
            'stop' => $request->stop === [] ? null : $request->stop,
            'reasoning_effort' => $request->effort?->value,
        ];

        return array_replace_recursive(
            $payload,
            array_filter($settings, fn ($value) => $value !== null),
            $request->options($this->getName()),
        );
    }

    /**
     * Name of the output token limit parameter.
     */
    protected function tokens(): string
    {
        return 'max_completion_tokens';
    }

    /**
     * @param  list<Message>  $messages
     * @return list<array<string, mixed>>
     */
    protected function messages(array $messages): array
    {
        $formatted = [];

        foreach ($messages as $message) {
            switch ($message->role) {
                case Role::System:
                    $formatted[] = ['role' => 'system', 'content' => $message->text()];
                    break;

                case Role::User:
                    $formatted[] = ['role' => 'user', 'content' => $this->content($message)];
                    break;

                case Role::Assistant:
                    $entry = ['role' => 'assistant', 'content' => $message->text() !== '' ? $message->text() : null];
                    $calls = array_map(fn (ToolCall $call) => [
                        'id' => $call->id,
                        'type' => 'function',
                        'function' => [
                            'name' => $call->name,
                            'arguments' => json_encode($this->object($call->arguments)) ?: '{}',
                        ],
                    ], $message->toolCalls());
                    if ($calls !== []) {
                        $entry['tool_calls'] = $calls;
                    }
                    $formatted[] = $entry;
                    break;

                case Role::Tool:
                    foreach ($message->toolResults() as $result) {
                        $formatted[] = ['role' => 'tool', 'tool_call_id' => $result->id, 'content' => $result->text()];
                    }
                    break;
            }
        }

        return $formatted;
    }

    /**
     * User content: plain text when there is only text, content parts otherwise.
     *
     * @return string|list<array<string, mixed>>
     */
    protected function content(Message $message): string|array
    {
        $media = array_filter($message->parts, fn (Part $part) => ! $part instanceof Text);
        if ($media === []) {
            return $message->text();
        }

        return array_map(fn (Part $part) => match (true) {
            $part instanceof Text => ['type' => 'text', 'text' => $part->text],
            $part instanceof Image => ['type' => 'image_url', 'image_url' => ['url' => $part->url ?? 'data:'.$part->mimeType.';base64,'.$part->base64()]],
            $part instanceof File && $part->url === null => ['type' => 'file', 'file' => ['filename' => $part->name ?? 'file', 'file_data' => 'data:'.$part->mimeType.';base64,'.$part->base64()]],
            default => throw new Unsupported(ucfirst($this->getName()).' does not accept '.$part::class.' in user messages'),
        }, $message->parts);
    }

    /**
     * @param  array<mixed>  $usage
     */
    protected function usage(array $usage): Usage
    {
        $prompt = \is_array($usage['prompt_tokens_details'] ?? null) ? $usage['prompt_tokens_details'] : [];
        $completion = \is_array($usage['completion_tokens_details'] ?? null) ? $usage['completion_tokens_details'] : [];

        return new Usage(
            input: \is_int($usage['prompt_tokens'] ?? null) ? $usage['prompt_tokens'] : 0,
            output: \is_int($usage['completion_tokens'] ?? null) ? $usage['completion_tokens'] : 0,
            cacheRead: \is_int($prompt['cached_tokens'] ?? null) ? $prompt['cached_tokens'] : 0,
            reasoning: \is_int($completion['reasoning_tokens'] ?? null) ? $completion['reasoning_tokens'] : 0,
        );
    }

    protected function finish(string $reason): FinishReason
    {
        return match ($reason) {
            'stop' => FinishReason::Stop,
            'length' => FinishReason::Length,
            'tool_calls', 'function_call' => FinishReason::ToolCalls,
            'content_filter' => FinishReason::ContentFilter,
            default => FinishReason::Other,
        };
    }

    protected function headers(): array
    {
        return ['authorization' => 'Bearer '.$this->apiKey];
    }
}
