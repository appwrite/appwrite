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
use Utopia\Agents\Request;
use Utopia\Agents\Response;
use Utopia\Agents\Role;
use Utopia\Agents\Tool;
use Utopia\Agents\ToolChoice;
use Utopia\Agents\Usage;
use Utopia\Client\Psr18\StreamingClientInterface;

/**
 * OpenAI, over the Responses API, which unlike Chat Completions lets reasoning
 * models reason and call tools in the same request.
 *
 * Nothing is stored on OpenAI's side: each request carries the whole
 * conversation. With reasoning on, the encrypted reasoning items come back as
 * signed reasoning parts and are sent back on the next turn, so a model keeps
 * its reasoning across tool calls. Reasoning summaries are off unless asked
 * for through the options: ['openai' => ['reasoning' => ['summary' => 'auto']]].
 *
 * The Responses API takes no stop sequences, so a request with any is
 * refused, and no seed, which is ignored. OpenAI-compatible providers speak
 * Chat Completions: see the Compatible adapter.
 */
class OpenAI extends Adapter implements Model
{
    protected const string ENDPOINT = 'https://api.openai.com/v1/responses';

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
        /** @var array<int, array<mixed>> $items */
        $items = [];
        /** @var array<string, array{id: string, name: string}> $calls */
        $calls = [];
        $usage = new Usage();
        $id = '';
        $model = $this->model;
        $status = '';
        $reason = '';

        $this->events($this->endpoint, $this->payload($request), function (string $name, array $event) use (&$items, &$calls, &$usage, &$id, &$model, &$status, &$reason, $listener): void {
            $type = \is_string($event['type'] ?? null) ? $event['type'] : $name;

            switch ($type) {
                case 'error':
                    $error = \is_array($event['error'] ?? null) ? $event['error'] : $event;
                    throw $this->failure($error);

                case 'response.failed':
                    $response = \is_array($event['response'] ?? null) ? $event['response'] : [];
                    throw $this->failure(\is_array($response['error'] ?? null) ? $response['error'] : []);

                case 'response.created':
                case 'response.in_progress':
                case 'response.completed':
                case 'response.incomplete':
                    $response = \is_array($event['response'] ?? null) ? $event['response'] : [];
                    if (\is_string($response['id'] ?? null)) {
                        $id = $response['id'];
                    }
                    if (\is_string($response['model'] ?? null)) {
                        $model = $response['model'];
                    }
                    if (\is_string($response['status'] ?? null)) {
                        $status = $response['status'];
                    }
                    if (\is_array($response['usage'] ?? null)) {
                        $usage = $this->usage($response['usage']);
                    }
                    $details = \is_array($response['incomplete_details'] ?? null) ? $response['incomplete_details'] : [];
                    if (\is_string($details['reason'] ?? null)) {
                        $reason = $details['reason'];
                    }
                    // The final response lists every output item: it fills any whose done event was missed.
                    $output = \is_array($response['output'] ?? null) ? $response['output'] : [];
                    foreach ($output as $index => $item) {
                        if (\is_int($index) && \is_array($item)) {
                            $items[$index] ??= $item;
                        }
                    }
                    break;

                case 'response.output_item.added':
                    $item = \is_array($event['item'] ?? null) ? $event['item'] : [];
                    if (($item['type'] ?? null) === 'function_call' && \is_string($item['id'] ?? null)) {
                        $calls[$item['id']] = [
                            'id' => \is_string($item['call_id'] ?? null) ? $item['call_id'] : $item['id'],
                            'name' => \is_string($item['name'] ?? null) ? $item['name'] : '',
                        ];
                    }
                    break;

                case 'response.output_item.done':
                    $item = \is_array($event['item'] ?? null) ? $event['item'] : [];
                    $index = \is_int($event['output_index'] ?? null) ? $event['output_index'] : \count($items);
                    $items[$index] = $item;
                    if (($item['type'] ?? null) === 'function_call') {
                        $listener(new ToolCallEvent($this->call($item, $index)));
                    }
                    break;

                case 'response.output_text.delta':
                case 'response.refusal.delta':
                    if (\is_string($event['delta'] ?? null) && $event['delta'] !== '') {
                        $listener(new TextDelta($event['delta']));
                    }
                    break;

                case 'response.reasoning_summary_text.delta':
                case 'response.reasoning_text.delta':
                    if (\is_string($event['delta'] ?? null) && $event['delta'] !== '') {
                        $listener(new ReasoningDelta($event['delta']));
                    }
                    break;

                case 'response.function_call_arguments.delta':
                    $call = $calls[\is_string($event['item_id'] ?? null) ? $event['item_id'] : ''] ?? ['id' => '', 'name' => ''];
                    if (\is_string($event['delta'] ?? null) && $event['delta'] !== '') {
                        $listener(new ToolInputDelta($call['id'], $call['name'], $event['delta']));
                    }
                    break;
            }
        });

        ksort($items);
        $parts = [];
        $called = false;
        foreach ($items as $index => $item) {
            switch ($item['type'] ?? null) {
                case 'reasoning':
                    $text = $this->texts($item['summary'] ?? null, 'summary_text').$this->texts($item['content'] ?? null, 'reasoning_text');
                    $encrypted = \is_string($item['encrypted_content'] ?? null) && $item['encrypted_content'] !== '' ? $item['encrypted_content'] : null;
                    if ($text !== '' || $encrypted !== null) {
                        $parts[] = new Reasoning($text, $encrypted, id: \is_string($item['id'] ?? null) ? $item['id'] : null);
                    }
                    break;

                case 'message':
                    $text = $this->texts($item['content'] ?? null, 'output_text').$this->texts($item['content'] ?? null, 'refusal', 'refusal');
                    if ($text !== '') {
                        $parts[] = new Text($text);
                    }
                    break;

                case 'function_call':
                    $parts[] = $this->call($item, $index);
                    $called = true;
                    break;
            }
        }

        $finish = match (true) {
            $status === 'incomplete' => match ($reason) {
                'max_output_tokens' => FinishReason::Length,
                'content_filter' => FinishReason::ContentFilter,
                default => FinishReason::Other,
            },
            $called => FinishReason::ToolCalls,
            $status === 'completed' => FinishReason::Stop,
            default => FinishReason::Other,
        };

        return new Response($parts, $finish, $usage, $id, $model);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(Request $request): array
    {
        if ($request->stop !== []) {
            throw new Unsupported('The OpenAI Responses API does not accept stop sequences');
        }

        $payload = [
            'model' => $this->model,
            'input' => $this->input($request->messages),
            'stream' => true,
            'store' => false,
        ];

        if ($request->tools !== []) {
            // Not strict, as with Chat Completions: strict tools must make every property required.
            $payload['tools'] = array_map(fn (Tool $tool) => [
                'type' => 'function',
                'name' => $tool->name,
                'description' => $tool->description,
                'parameters' => $tool->input->toArray(),
                'strict' => false,
            ], $request->tools);
        }

        if ($request->toolChoice !== null) {
            $payload['tool_choice'] = match ($request->toolChoice->type) {
                ToolChoice::TOOL => ['type' => 'function', 'name' => $request->toolChoice->name],
                default => $request->toolChoice->type,
            };
        }

        if ($request->schema !== null) {
            $payload['text'] = [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'response',
                    'strict' => true,
                    'schema' => $request->schema->toArray(),
                ],
            ];
        }

        if ($request->effort !== null) {
            $payload['reasoning'] = ['effort' => $request->effort->value];
        }

        // Reasoning kept across turns without storing it: the encrypted items come back to be sent again.
        if ($request->effort !== null && $request->effort !== Effort::None) {
            $payload['include'] = ['reasoning.encrypted_content'];
        }

        $sampling = $this->samples($request);
        $settings = [
            'max_output_tokens' => $request->maxTokens,
            'temperature' => $sampling ? $request->temperature : null,
            'top_p' => $sampling ? $request->topP : null,
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
     * Whether the model accepts temperature and top_p. GPT-6 models reject
     * them unless reasoning is off, and reasoning is on by default.
     */
    protected function samples(Request $request): bool
    {
        if (! str_starts_with($this->model, 'gpt-6')) {
            return true;
        }

        return $request->effort === Effort::None;
    }

    /**
     * The conversation as Responses input items. Reasoning without its
     * encrypted content can't be sent back, so it is left out.
     *
     * @param  list<Message>  $messages
     * @return list<array<string, mixed>>
     */
    protected function input(array $messages): array
    {
        $input = [];

        foreach ($messages as $message) {
            switch ($message->role) {
                case Role::System:
                    $input[] = ['role' => 'system', 'content' => $message->text()];
                    break;

                case Role::User:
                    $input[] = ['role' => 'user', 'content' => $this->content($message)];
                    break;

                case Role::Assistant:
                    foreach ($message->parts as $part) {
                        if ($part instanceof Reasoning && $part->signature !== null) {
                            $item = ['type' => 'reasoning'];
                            if ($part->id !== null) {
                                $item['id'] = $part->id;
                            }
                            $item['summary'] = $part->text === '' ? [] : [['type' => 'summary_text', 'text' => $part->text]];
                            $item['encrypted_content'] = $part->signature;
                            $input[] = $item;
                        } elseif ($part instanceof Text && $part->text !== '') {
                            $input[] = ['role' => 'assistant', 'content' => $part->text];
                        } elseif ($part instanceof ToolCall) {
                            $input[] = [
                                'type' => 'function_call',
                                'call_id' => $part->id,
                                'name' => $part->name,
                                'arguments' => json_encode($this->object($part->arguments)) ?: '{}',
                            ];
                        }
                    }
                    break;

                case Role::Tool:
                    foreach ($message->toolResults() as $result) {
                        $input[] = ['type' => 'function_call_output', 'call_id' => $result->id, 'output' => $result->text()];
                    }
                    break;
            }
        }

        return $input;
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
            $part instanceof Text => ['type' => 'input_text', 'text' => $part->text],
            $part instanceof Image => ['type' => 'input_image', 'image_url' => $part->url ?? 'data:'.$part->mimeType.';base64,'.$part->base64()],
            $part instanceof File && $part->url !== null => ['type' => 'input_file', 'file_url' => $part->url],
            $part instanceof File => ['type' => 'input_file', 'filename' => $part->name ?? 'file', 'file_data' => 'data:'.$part->mimeType.';base64,'.$part->base64()],
            default => throw new Unsupported('OpenAI does not accept '.$part::class.' in user messages'),
        }, $message->parts);
    }

    /**
     * A function call output item as a tool call part.
     *
     * @param  array<mixed>  $item
     */
    protected function call(array $item, int $index): ToolCall
    {
        $id = \is_string($item['call_id'] ?? null) && $item['call_id'] !== '' ? $item['call_id'] : 'call_'.$index;
        $name = \is_string($item['name'] ?? null) ? $item['name'] : '';
        $arguments = \is_string($item['arguments'] ?? null) ? $item['arguments'] : '';

        return new ToolCall($id, $name, $this->arguments($arguments));
    }

    /**
     * The text of every content part of one type, joined.
     */
    protected function texts(mixed $parts, string $type, string $key = 'text'): string
    {
        if (! \is_array($parts)) {
            return '';
        }

        $text = '';
        foreach ($parts as $part) {
            if (\is_array($part) && ($part['type'] ?? null) === $type && \is_string($part[$key] ?? null)) {
                $text .= $part[$key];
            }
        }

        return $text;
    }

    /**
     * An error reported inside the stream.
     *
     * @param  array<mixed>  $error
     */
    protected function failure(array $error): Api
    {
        $type = '';
        foreach (['code', 'type'] as $key) {
            if (isset($error[$key]) && \is_scalar($error[$key]) && (string) $error[$key] !== '' && $error[$key] !== 'error') {
                $type = (string) $error[$key];
                break;
            }
        }
        $message = \is_string($error['message'] ?? null) ? $error['message'] : 'Unknown error';
        $retryable = \in_array($type, ['server_error', 'rate_limit_exceeded'], true);

        return new Api('Openai API error'.($type !== '' ? " ({$type})" : '').": {$message}", $this->getName(), type: $type, retryable: $retryable);
    }

    /**
     * @param  array<mixed>  $usage
     */
    protected function usage(array $usage): Usage
    {
        $input = \is_array($usage['input_tokens_details'] ?? null) ? $usage['input_tokens_details'] : [];
        $output = \is_array($usage['output_tokens_details'] ?? null) ? $usage['output_tokens_details'] : [];

        return new Usage(
            input: \is_int($usage['input_tokens'] ?? null) ? $usage['input_tokens'] : 0,
            output: \is_int($usage['output_tokens'] ?? null) ? $usage['output_tokens'] : 0,
            cacheRead: \is_int($input['cached_tokens'] ?? null) ? $input['cached_tokens'] : 0,
            reasoning: \is_int($output['reasoning_tokens'] ?? null) ? $output['reasoning_tokens'] : 0,
        );
    }

    protected function headers(): array
    {
        return ['authorization' => 'Bearer '.$this->apiKey];
    }
}
