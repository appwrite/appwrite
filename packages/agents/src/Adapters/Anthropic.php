<?php

namespace Utopia\Agents\Adapters;

use Psr\Http\Client\ClientInterface;
use Utopia\Agents\Adapter;
use Utopia\Agents\Effort;
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
use Utopia\Agents\Parts\ToolResult;
use Utopia\Agents\Request;
use Utopia\Agents\Response;
use Utopia\Agents\Role;
use Utopia\Agents\Tool;
use Utopia\Agents\ToolChoice;
use Utopia\Agents\Usage;
use Utopia\Client\Psr18\StreamingClientInterface;

/**
 * Anthropic Messages API.
 *
 * Marks the system prompt (or, without one, the last tool) as a prompt cache
 * breakpoint. The request follows the model generation:
 *
 * - Structured output uses the API's JSON schema output format from Claude
 *   4.5 on. Older models get a "json" tool whose input is returned, and
 *   streamed, as the response text.
 * - Effort maps to adaptive thinking and the API's effort setting from Claude
 *   4.6 on, and to a thinking token budget before that. Effort::None leaves
 *   thinking off where the model allows it; from Claude 5 on, where thinking
 *   cannot always be turned off, it asks for the lowest effort instead.
 * - Temperature, top_p and top_k are sent only up to Claude 4.6: later models
 *   reject them.
 *
 * A model ID without a version, such as an alias, is treated as current.
 */
class Anthropic extends Adapter implements Model
{
    protected const string ENDPOINT = 'https://api.anthropic.com/v1/messages';

    protected const string VERSION = '2023-06-01';

    /**
     * Name of the tool that carries structured output.
     */
    protected const string OUTPUT = 'json';

    /**
     * Output token limit when the request sets none; the API requires one.
     */
    protected const int MAX_TOKENS = 4096;

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
        return 'anthropic';
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
        /** @var array<int, array{type: string, text: string, signature: string, id: string, name: string, json: string}> $blocks */
        $blocks = [];
        /** @var string|null $stop */
        $stop = null;
        /** @var array<mixed> $usage */
        $usage = [];
        $id = '';
        $model = $this->model;

        $this->events($this->endpoint, $this->payload($request), function (string $name, array $event) use (&$blocks, &$stop, &$usage, &$id, &$model, $listener): void {
            $type = $event['type'] ?? $name;

            switch ($type) {
                case 'message_start':
                    $message = \is_array($event['message'] ?? null) ? $event['message'] : [];
                    $id = \is_string($message['id'] ?? null) ? $message['id'] : $id;
                    $model = \is_string($message['model'] ?? null) ? $message['model'] : $model;
                    $usage = \is_array($message['usage'] ?? null) ? $message['usage'] : [];
                    break;

                case 'content_block_start':
                    $index = \is_int($event['index'] ?? null) ? $event['index'] : \count($blocks);
                    $block = \is_array($event['content_block'] ?? null) ? $event['content_block'] : [];
                    $blocks[$index] = [
                        'type' => \is_string($block['type'] ?? null) ? $block['type'] : '',
                        'text' => \is_string($block['text'] ?? null) ? $block['text'] : (\is_string($block['thinking'] ?? null) ? $block['thinking'] : ''),
                        'signature' => \is_string($block['signature'] ?? null) ? $block['signature'] : (\is_string($block['data'] ?? null) ? $block['data'] : ''),
                        'id' => \is_string($block['id'] ?? null) ? $block['id'] : '',
                        'name' => \is_string($block['name'] ?? null) ? $block['name'] : '',
                        'json' => '',
                    ];
                    break;

                case 'content_block_delta':
                    $index = \is_int($event['index'] ?? null) ? $event['index'] : -1;
                    $delta = \is_array($event['delta'] ?? null) ? $event['delta'] : [];
                    if (! isset($blocks[$index])) {
                        break;
                    }

                    $deltaType = $delta['type'] ?? '';
                    if ($deltaType === 'text_delta' && \is_string($delta['text'] ?? null)) {
                        $blocks[$index]['text'] .= $delta['text'];
                        $listener(new TextDelta($delta['text']));
                    } elseif ($deltaType === 'thinking_delta' && \is_string($delta['thinking'] ?? null)) {
                        $blocks[$index]['text'] .= $delta['thinking'];
                        $listener(new ReasoningDelta($delta['thinking']));
                    } elseif ($deltaType === 'signature_delta' && \is_string($delta['signature'] ?? null)) {
                        $blocks[$index]['signature'] .= $delta['signature'];
                    } elseif ($deltaType === 'input_json_delta' && \is_string($delta['partial_json'] ?? null) && $delta['partial_json'] !== '') {
                        $blocks[$index]['json'] .= $delta['partial_json'];
                        $listener($this->output($blocks[$index])
                            ? new TextDelta($delta['partial_json'])
                            : new ToolInputDelta($blocks[$index]['id'], $blocks[$index]['name'], $delta['partial_json']));
                    }
                    break;

                case 'content_block_stop':
                    $index = \is_int($event['index'] ?? null) ? $event['index'] : -1;
                    if (isset($blocks[$index]) && $blocks[$index]['type'] === 'tool_use' && ! $this->output($blocks[$index])) {
                        $listener(new ToolCallEvent($this->call($blocks[$index])));
                    }
                    break;

                case 'message_delta':
                    $delta = \is_array($event['delta'] ?? null) ? $event['delta'] : [];
                    if (\is_string($delta['stop_reason'] ?? null)) {
                        $stop = $delta['stop_reason'];
                    }
                    if (\is_array($event['usage'] ?? null)) {
                        $usage = array_replace($usage, array_filter($event['usage'], fn ($value) => $value !== null));
                    }
                    break;

                case 'error':
                    $error = \is_array($event['error'] ?? null) ? $event['error'] : [];
                    $errorType = \is_string($error['type'] ?? null) ? $error['type'] : 'unknown_error';
                    $message = \is_string($error['message'] ?? null) ? $error['message'] : 'Unknown error';
                    throw new Api("Anthropic API error ({$errorType}): {$message}", $this->getName(), type: $errorType, retryable: \in_array($errorType, ['overloaded_error', 'api_error'], true));
            }
        });

        ksort($blocks);
        $parts = [];
        $calls = 0;
        foreach ($blocks as $block) {
            $part = match ($block['type']) {
                'text' => $block['text'] !== '' ? new Text($block['text']) : null,
                'thinking' => new Reasoning($block['text'], $block['signature'] !== '' ? $block['signature'] : null),
                'redacted_thinking' => new Reasoning('', $block['signature'], true),
                'tool_use' => $this->output($block) ? new Text($block['json']) : $this->call($block),
                default => null,
            };
            if ($part instanceof ToolCall) {
                $calls++;
            }
            if ($part !== null) {
                $parts[] = $part;
            }
        }

        $finish = match ($stop) {
            'end_turn', 'stop_sequence' => FinishReason::Stop,
            'max_tokens', 'model_context_window_exceeded' => FinishReason::Length,
            'tool_use' => $calls > 0 ? FinishReason::ToolCalls : FinishReason::Stop,
            'refusal' => FinishReason::ContentFilter,
            default => FinishReason::Other,
        };

        return new Response($parts, $finish, $this->usage($usage), $id, $model);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(Request $request): array
    {
        $payload = [
            'model' => $this->model,
            'max_tokens' => $request->maxTokens ?? static::MAX_TOKENS,
            'messages' => $this->messages($request->messages),
            'stream' => true,
        ];

        $system = $request->system();
        if ($system !== '') {
            $payload['system'] = [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]];
        }

        $version = $this->version();
        // Structured output through the API's own format, or through the json tool for models without it.
        $native = $version === null || $version >= 4.5;

        $tools = array_map(fn (Tool $tool) => [
            'name' => $tool->name,
            'description' => $tool->description,
            'input_schema' => $tool->input->toArray(),
        ], $request->tools);

        if ($request->schema !== null && $native) {
            $payload['output_config']['format'] = ['type' => 'json_schema', 'schema' => $request->schema->toArray()];
        }

        if ($request->schema !== null && ! $native) {
            $tools[] = [
                'name' => static::OUTPUT,
                'description' => 'Respond with a JSON object matching this schema. Call this tool to give your final answer.',
                'input_schema' => $request->schema->toArray(),
            ];
        }

        if ($tools !== []) {
            if ($system === '') {
                $tools[\count($tools) - 1]['cache_control'] = ['type' => 'ephemeral'];
            }
            $payload['tools'] = $tools;
        }

        $choice = $request->toolChoice;
        if ($choice === null && $request->schema !== null && ! $native && $request->tools === [] && ($request->effort === null || $request->effort === Effort::None)) {
            $choice = ToolChoice::tool(static::OUTPUT);
        }
        if ($choice !== null) {
            $payload['tool_choice'] = match ($choice->type) {
                ToolChoice::REQUIRED => ['type' => 'any'],
                ToolChoice::TOOL => ['type' => 'tool', 'name' => $choice->name],
                default => ['type' => $choice->type],
            };
        }

        $effort = $request->effort;
        if ($effort !== null && $version !== null && $version < 4.6) {
            if ($effort !== Effort::None) {
                $budget = $effort->budget();
                $payload['thinking'] = ['type' => 'enabled', 'budget_tokens' => $budget];
                $payload['max_tokens'] = max($payload['max_tokens'], $budget + static::MAX_TOKENS);
            }
        } elseif ($effort === Effort::None) {
            // Before Claude 5 thinking is off unless asked for; from 5 on some models always think.
            if ($version === null || $version >= 5) {
                $payload['output_config']['effort'] = Effort::Low->value;
            }
        } elseif ($effort !== null) {
            $payload['thinking'] = ['type' => 'adaptive'];
            // xhigh arrived with Claude 4.7.
            $payload['output_config']['effort'] = $effort === Effort::XHigh && $version !== null && $version < 4.7
                ? Effort::High->value
                : $effort->value;
        }

        $sampling = $version !== null && $version < 4.7;
        $settings = [
            'temperature' => $sampling ? $request->temperature : null,
            'top_p' => $sampling ? $request->topP : null,
            'top_k' => $sampling ? $request->topK : null,
            'stop_sequences' => $request->stop === [] ? null : $request->stop,
        ];

        /** @var array<string, mixed> $merged */
        $merged = array_replace_recursive(
            $payload,
            array_filter($settings, fn ($value) => $value !== null),
            $request->options($this->getName()),
        );

        return $merged;
    }

    /**
     * The model's generation as major.minor, from IDs such as claude-opus-4-1-20250805,
     * claude-sonnet-4-20250514, claude-3-5-haiku-latest or claude-haiku-5-5; null when
     * the ID names none.
     */
    protected function version(): ?float
    {
        $numbers = array_values(array_filter(explode('-', $this->model), fn (string $part) => ctype_digit($part)));
        if ($numbers === [] || \strlen($numbers[0]) > 2) {
            return null;
        }

        // A date suffix is no minor version.
        $minor = isset($numbers[1]) && \strlen($numbers[1]) <= 2 ? (int) $numbers[1] : 0;

        return (int) $numbers[0] + $minor / 10;
    }

    /**
     * Messages as Anthropic turns: tool results travel in user turns and
     * consecutive turns of one role are merged, as the API requires.
     *
     * @param  list<Message>  $messages
     * @return list<array{role: string, content: list<array<string, mixed>>}>
     */
    protected function messages(array $messages): array
    {
        $turns = [];

        foreach ($messages as $message) {
            if ($message->role === Role::System) {
                continue;
            }

            $role = $message->role === Role::Assistant ? 'assistant' : 'user';
            $content = [];
            foreach ($message->parts as $part) {
                $block = $this->block($part);
                if ($block !== null) {
                    $content[] = $block;
                }
            }
            if ($content === []) {
                continue;
            }

            $last = \count($turns) - 1;
            if ($last >= 0 && $turns[$last]['role'] === $role) {
                $turns[$last]['content'] = [...$turns[$last]['content'], ...$content];
            } else {
                $turns[] = ['role' => $role, 'content' => $content];
            }
        }

        return $turns;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function block(Part $part): ?array
    {
        return match (true) {
            $part instanceof Text => $part->text === '' ? null : ['type' => 'text', 'text' => $part->text],
            $part instanceof Image => ['type' => 'image', 'source' => $part->url !== null
                ? ['type' => 'url', 'url' => $part->url]
                : ['type' => 'base64', 'media_type' => $part->mimeType, 'data' => $part->base64()]],
            $part instanceof File => ['type' => 'document', 'source' => $part->url !== null
                ? ['type' => 'url', 'url' => $part->url]
                : ['type' => 'base64', 'media_type' => $part->mimeType, 'data' => $part->base64()]],
            $part instanceof Reasoning => match (true) {
                $part->signature === null => null,
                $part->redacted => ['type' => 'redacted_thinking', 'data' => $part->signature],
                default => ['type' => 'thinking', 'thinking' => $part->text, 'signature' => $part->signature],
            },
            $part instanceof ToolCall => ['type' => 'tool_use', 'id' => $part->id, 'name' => $part->name, 'input' => $this->object($part->arguments)],
            $part instanceof ToolResult => ['type' => 'tool_result', 'tool_use_id' => $part->id, 'content' => $part->text(), 'is_error' => $part->error],
            default => throw new Unsupported('Anthropic does not accept '.$part::class),
        };
    }

    /**
     * @param  array{type: string, text: string, signature: string, id: string, name: string, json: string}  $block
     */
    protected function call(array $block): ToolCall
    {
        return new ToolCall($block['id'], $block['name'], $this->arguments($block['json']));
    }

    /**
     * Whether a block is the structured output tool.
     *
     * @param  array{type: string, text: string, signature: string, id: string, name: string, json: string}  $block
     */
    protected function output(array $block): bool
    {
        return $block['type'] === 'tool_use' && $block['name'] === static::OUTPUT;
    }

    /**
     * @param  array<mixed>  $usage
     */
    protected function usage(array $usage): Usage
    {
        $read = \is_int($usage['cache_read_input_tokens'] ?? null) ? $usage['cache_read_input_tokens'] : 0;
        $write = \is_int($usage['cache_creation_input_tokens'] ?? null) ? $usage['cache_creation_input_tokens'] : 0;
        $input = \is_int($usage['input_tokens'] ?? null) ? $usage['input_tokens'] : 0;

        return new Usage(
            input: $input + $read + $write,
            output: \is_int($usage['output_tokens'] ?? null) ? $usage['output_tokens'] : 0,
            cacheRead: $read,
            cacheWrite: $write,
        );
    }

    protected function headers(): array
    {
        return [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => static::VERSION,
        ];
    }
}
