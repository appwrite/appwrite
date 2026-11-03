<?php

namespace Utopia\Agents\Adapters;

use Psr\Http\Client\ClientInterface;
use Utopia\Agents\Adapter;
use Utopia\Agents\Event;
use Utopia\Agents\Events\ReasoningDelta;
use Utopia\Agents\Events\TextDelta;
use Utopia\Agents\Events\ToolCall as ToolCallEvent;
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
 * Google Gemini API (generateContent).
 *
 * Tool and output schemas are sent as JSON Schema. Thought signatures on
 * function calls are kept on the ToolCall and sent back with it, as thinking
 * models require. Effort sets the thinking budget and returns thought
 * summaries as reasoning.
 */
class Gemini extends Adapter implements Model
{
    protected const string ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta';

    protected string $endpoint;

    /**
     * @param  string|null  $endpoint  API base URL, without the /models path
     */
    public function __construct(
        protected string $apiKey,
        protected string $model,
        ?string $endpoint = null,
        int $timeout = 90000,
        (ClientInterface&StreamingClientInterface)|null $client = null,
    ) {
        $this->endpoint = rtrim($endpoint ?? static::ENDPOINT, '/');
        $this->timeout = $timeout;
        $this->client = $client;
    }

    public function getName(): string
    {
        return 'gemini';
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
        /** @var string|null $signature */
        $signature = null;
        /** @var list<ToolCall> $calls */
        $calls = [];
        /** @var string|null $reason */
        $reason = null;
        $usage = new Usage();
        $id = '';
        $model = $this->model;

        $url = "{$this->endpoint}/models/{$this->model}:streamGenerateContent?alt=sse";

        $this->events($url, $this->payload($request), function (string $name, array $chunk) use (&$text, &$reasoning, &$signature, &$calls, &$reason, &$usage, &$id, &$model, $listener): void {
            if (\is_string($chunk['responseId'] ?? null)) {
                $id = $chunk['responseId'];
            }
            if (\is_string($chunk['modelVersion'] ?? null)) {
                $model = $chunk['modelVersion'];
            }
            if (\is_array($chunk['usageMetadata'] ?? null)) {
                $usage = $this->usage($chunk['usageMetadata']);
            }

            $feedback = \is_array($chunk['promptFeedback'] ?? null) ? $chunk['promptFeedback'] : [];
            if (\is_string($feedback['blockReason'] ?? null)) {
                $reason = 'SAFETY';
            }

            $candidates = \is_array($chunk['candidates'] ?? null) ? $chunk['candidates'] : [];
            $candidate = \is_array($candidates[0] ?? null) ? $candidates[0] : [];
            if (\is_string($candidate['finishReason'] ?? null)) {
                $reason = $candidate['finishReason'];
            }

            $content = \is_array($candidate['content'] ?? null) ? $candidate['content'] : [];
            $parts = \is_array($content['parts'] ?? null) ? $content['parts'] : [];

            foreach ($parts as $part) {
                if (! \is_array($part)) {
                    continue;
                }

                $partSignature = \is_string($part['thoughtSignature'] ?? null) ? $part['thoughtSignature'] : null;

                if (\is_array($part['functionCall'] ?? null)) {
                    $function = $part['functionCall'];
                    $call = new ToolCall(
                        \is_string($function['id'] ?? null) ? $function['id'] : 'call_'.\count($calls),
                        \is_string($function['name'] ?? null) ? $function['name'] : '',
                        $this->map($function['args'] ?? null),
                        $partSignature,
                    );
                    $calls[] = $call;
                    $listener(new ToolCallEvent($call));

                    continue;
                }

                if (! \is_string($part['text'] ?? null)) {
                    continue;
                }

                if (($part['thought'] ?? false) === true) {
                    $reasoning .= $part['text'];
                    $signature = $partSignature ?? $signature;
                    if ($part['text'] !== '') {
                        $listener(new ReasoningDelta($part['text']));
                    }

                    continue;
                }

                $signature = $partSignature ?? $signature;
                if ($part['text'] !== '') {
                    $text .= $part['text'];
                    $listener(new TextDelta($part['text']));
                }
            }
        });

        $parts = [];
        if ($reasoning !== '' || $signature !== null) {
            $parts[] = new Reasoning($reasoning, $signature);
        }
        if ($text !== '') {
            $parts[] = new Text($text);
        }
        $parts = [...$parts, ...$calls];

        $finish = match ($reason) {
            'STOP' => $calls !== [] ? FinishReason::ToolCalls : FinishReason::Stop,
            'MAX_TOKENS' => FinishReason::Length,
            'SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII', 'IMAGE_SAFETY' => FinishReason::ContentFilter,
            default => $calls !== [] ? FinishReason::ToolCalls : FinishReason::Other,
        };

        return new Response($parts, $finish, $usage, $id, $model);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(Request $request): array
    {
        $payload = ['contents' => $this->contents($request->messages)];

        $system = $request->system();
        if ($system !== '') {
            $payload['systemInstruction'] = ['parts' => [['text' => $system]]];
        }

        if ($request->tools !== []) {
            $payload['tools'] = [[
                'functionDeclarations' => array_map(fn (Tool $tool) => [
                    'name' => $tool->name,
                    'description' => $tool->description,
                    'parametersJsonSchema' => $tool->input->toArray(),
                ], $request->tools),
            ]];
        }

        if ($request->toolChoice !== null) {
            $config = match ($request->toolChoice->type) {
                ToolChoice::NONE => ['mode' => 'NONE'],
                ToolChoice::REQUIRED => ['mode' => 'ANY'],
                ToolChoice::TOOL => ['mode' => 'ANY', 'allowedFunctionNames' => [$request->toolChoice->name]],
                default => ['mode' => 'AUTO'],
            };
            $payload['toolConfig'] = ['functionCallingConfig' => $config];
        }

        $config = [
            'maxOutputTokens' => $request->maxTokens,
            'temperature' => $request->temperature,
            'topP' => $request->topP,
            'topK' => $request->topK,
            'stopSequences' => $request->stop === [] ? null : $request->stop,
            'seed' => $request->seed,
        ];

        if ($request->schema !== null) {
            $config['responseMimeType'] = 'application/json';
            $config['responseJsonSchema'] = $request->schema->toArray();
        }

        if ($request->effort !== null) {
            // Gemini caps the budget per model: 24576 on Flash, 32768 on Pro.
            $budget = min($request->effort->budget(), str_contains($this->model, 'flash') ? 24576 : 32768);
            $config['thinkingConfig'] = ['thinkingBudget' => $budget, 'includeThoughts' => $budget > 0];
        }

        $config = array_filter($config, fn ($value) => $value !== null);
        if ($config !== []) {
            $payload['generationConfig'] = $config;
        }

        return array_replace_recursive($payload, $request->options($this->getName()));
    }

    /**
     * Messages as Gemini contents: tool results travel in user turns and
     * consecutive turns of one role are merged.
     *
     * @param  list<Message>  $messages
     * @return list<array{role: string, parts: list<array<string, mixed>>}>
     */
    protected function contents(array $messages): array
    {
        $contents = [];

        foreach ($messages as $message) {
            if ($message->role === Role::System) {
                continue;
            }

            $role = $message->role === Role::Assistant ? 'model' : 'user';
            $parts = [];
            foreach ($message->parts as $part) {
                $formatted = $this->part($part);
                if ($formatted !== null) {
                    $parts[] = $formatted;
                }
            }
            if ($parts === []) {
                continue;
            }

            $last = \count($contents) - 1;
            if ($last >= 0 && $contents[$last]['role'] === $role) {
                $contents[$last]['parts'] = [...$contents[$last]['parts'], ...$parts];
            } else {
                $contents[] = ['role' => $role, 'parts' => $parts];
            }
        }

        return $contents;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function part(Part $part): ?array
    {
        return match (true) {
            $part instanceof Text => $part->text === '' ? null : ['text' => $part->text],
            $part instanceof Image, $part instanceof File => $part->url !== null
                ? ['fileData' => ['mimeType' => $part->mimeType, 'fileUri' => $part->url]]
                : ['inlineData' => ['mimeType' => $part->mimeType, 'data' => $part->base64()]],
            $part instanceof Reasoning => null,
            $part instanceof ToolCall => array_filter([
                'functionCall' => ['name' => $part->name, 'args' => $this->object($part->arguments)],
                'thoughtSignature' => $part->signature,
            ], fn ($value) => $value !== null),
            $part instanceof ToolResult => ['functionResponse' => [
                'name' => $part->name,
                'response' => $part->error ? ['error' => $part->text()] : ['output' => $part->output],
            ]],
            default => throw new Unsupported('Gemini does not accept '.$part::class),
        };
    }

    /**
     * @param  array<mixed>  $usage
     */
    protected function usage(array $usage): Usage
    {
        $thoughts = \is_int($usage['thoughtsTokenCount'] ?? null) ? $usage['thoughtsTokenCount'] : 0;

        return new Usage(
            input: \is_int($usage['promptTokenCount'] ?? null) ? $usage['promptTokenCount'] : 0,
            output: (\is_int($usage['candidatesTokenCount'] ?? null) ? $usage['candidatesTokenCount'] : 0) + $thoughts,
            cacheRead: \is_int($usage['cachedContentTokenCount'] ?? null) ? $usage['cachedContentTokenCount'] : 0,
            reasoning: $thoughts,
        );
    }

    protected function headers(): array
    {
        return ['x-goog-api-key' => $this->apiKey];
    }
}
