# Utopia Agents

> [!IMPORTANT]
> This repository is a read-only mirror of [`packages/agents`](https://github.com/appwrite/appwrite/tree/main/packages/agents) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/agents.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia Agents is a provider-neutral PHP library for building with language models: text generation, streaming, tool-calling agents, structured output and embeddings, behind one API for OpenAI, Anthropic, Gemini and OpenAI-compatible providers. This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting Started

```bash
composer require utopia-php/agents
```

Requires PHP 8.5 or later. Requests use a keep-alive cURL client, or a pooled Swoole client inside a coroutine.

## Features

- **One API, many providers**: OpenAI, Anthropic, Gemini, DeepSeek, xAI, OpenRouter and Perplexity, plus any OpenAI-compatible endpoint
- **Agents with tools**: a step loop that runs tool calls, feeds results back and stops on an answer, a step limit or your own condition
- **Structured output**: describe a JSON Schema, get a validated, decoded array back
- **Streaming**: typed events for text, reasoning, tool input, tool calls, tool results, steps and completion
- **Typed messages**: text, images, files (PDF), reasoning, tool calls and tool results
- **Reasoning**: one effort setting mapped to each provider's reasoning or thinking budget
- **Usage**: normalized input, output, cache and reasoning token counts per step and per run
- **Resilience**: typed exceptions, retries with exponential backoff and `Retry-After` on 408, 409, 429 and 5xx
- **Embeddings**: OpenAI, Ollama and Appwrite embedding services

## Usage

### Generate text

```php
use Utopia\Agents\Adapters\OpenAI;
use Utopia\Agents\Agent;

$agent = new Agent(new OpenAI(getenv('OPENAI_API_KEY'), 'gpt-5-mini'), 'You are a helpful assistant.');

$result = $agent->generate('What is the capital of France?');

echo $result->text;               // "Paris."
echo $result->finish->value;      // "stop"
echo $result->usage->total();     // input + output tokens
```

`generate()` accepts a string, a `Message` or a list of messages:

```php
use Utopia\Agents\Message;
use Utopia\Agents\Parts\Image;
use Utopia\Agents\Parts\Text;

$agent->generate([
    Message::user('My name is Ada.'),
    Message::assistant('Nice to meet you, Ada.'),
    Message::user([new Text('What is in this picture?'), new Image(file_get_contents('photo.png'), 'image/png')]),
]);
```

### Providers

Every adapter takes an API key and a model ID. The model ID is passed to the provider as is.

```php
use Utopia\Agents\Adapters\Anthropic;
use Utopia\Agents\Adapters\Deepseek;
use Utopia\Agents\Adapters\Gemini;
use Utopia\Agents\Adapters\OpenAI;
use Utopia\Agents\Adapters\OpenRouter;
use Utopia\Agents\Adapters\Perplexity;
use Utopia\Agents\Adapters\XAI;

new OpenAI($key, 'gpt-5-mini');
new Anthropic($key, 'claude-sonnet-5-5');
new Gemini($key, 'gemini-2.5-flash');
new Deepseek($key, 'deepseek-chat');
new XAI($key, 'grok-4');
new OpenRouter($key, 'anthropic/claude-sonnet-5.5', referer: 'https://example.com', title: 'My App');
new Perplexity($key, 'sonar');

// Any OpenAI-compatible server
new OpenAI($key, 'llama3.1', 'http://localhost:11434/v1/chat/completions');
```

### Tools

A tool has a name, a description, a JSON Schema for its input and a handler. The agent runs the handler with the validated arguments and sends the result back to the model, until the model answers.

```php
use Utopia\Agents\Parts\ToolCall;
use Utopia\Agents\Schema;
use Utopia\Agents\Tool;

$weather = new Tool(
    name: 'weather',
    description: 'Current temperature in Celsius for a city',
    input: Schema::object(['city' => Schema::string('City name')]),
    handler: fn (array $input, ToolCall $call) => ['temperature' => 23],
);

$result = $agent
    ->addTool($weather)
    ->setSteps(5)              // at most 5 model calls (default 10)
    ->generate('How warm is it in Lisbon?');

$result->steps;                // one Step per model call
$result->toolCalls();          // every call the model made
$result->toolResults();        // every result the agent sent back
```

Tool failures do not abort the run. An unknown tool, invalid arguments or an exception from the handler becomes an error result, so the model can recover.

Control the loop further with `setToolChoice(ToolChoice::required())` (also `auto()`, `none()`, `tool('weather')`) and `setUntil(fn (array $steps) => ...)`.

#### Client-side tools and approval

A tool without a handler is not run. The agent stops and returns the call, and you answer it yourself, for example after asking the user:

```php
use Utopia\Agents\Conversation;
use Utopia\Agents\Message;
use Utopia\Agents\Parts\ToolResult;

$agent->addTool(new Tool('confirm', 'Ask the user to confirm the booking', Schema::object([])));
$conversation = new Conversation($agent);

$result = $conversation->send('Book the 9am flight.');
foreach ($result->pending() as $call) {
    $conversation->add(Message::tool([new ToolResult($call->id, $call->name, 'confirmed')]));
}
$result = $conversation->send(); // continues with the answers
```

### Structured output

```php
$agent->setSchema(Schema::object([
    'name' => Schema::string(),
    'year' => Schema::integer(),
    'tags' => Schema::array(Schema::string()),
    'license' => Schema::nullable(Schema::enum(['MIT', 'BSD', 'GPL'])),
]));

$result = $agent->generate('Describe the PHP language.');
$result->output; // ['name' => 'PHP', 'year' => 1995, 'tags' => [...], 'license' => null]
```

`Schema::object()` marks every property required and forbids extra properties, which strict modes expect; use `Schema::nullable()` for optional values. `Schema::fromArray()` wraps any JSON Schema. Output that is not valid JSON or does not match the schema throws `Exception\Output`, which carries the raw text and the list of violations.

OpenAI and compatible providers use native JSON Schema output, Gemini `responseJsonSchema`, and Anthropic its JSON schema output format (Claude 4.5 and later; older models get a forced `json` tool whose input is returned as the text).

### Streaming

```php
use Utopia\Agents\Event;
use Utopia\Agents\Events;

$result = $agent->stream('Write a haiku about PHP.', function (Event $event): void {
    match (true) {
        $event instanceof Events\TextDelta => print $event->delta,
        $event instanceof Events\ReasoningDelta => null,  // thinking, when enabled
        $event instanceof Events\ToolInputDelta => null,  // tool arguments as they are generated
        $event instanceof Events\ToolCall => null,        // a complete tool call
        $event instanceof Events\ToolResult => null,      // a tool the agent ran
        $event instanceof Events\StepFinish => null,      // one model call done
        $event instanceof Events\Finish => null,          // the run is done; $event->result
        default => null,
    };
});
```

`stream()` returns the same `Result` as `generate()`.

### Conversations

`Conversation` keeps the history and sums the usage of every run:

```php
$conversation = new Conversation($agent);
$conversation->send('My favorite color is teal.');
$conversation->send('What is my favorite color?')->text; // "Teal."

$conversation->getMessages();
$conversation->getUsage()->total();
```

### Settings

```php
use Utopia\Agents\Effort;

$agent
    ->setMaxTokens(2048)
    ->setTemperature(0.2)        // sampling is left out for models that reject it, such as Claude 4.7 and later
    ->setTopP(0.9)
    ->setTopK(40)
    ->setStop(['END'])
    ->setSeed(42)
    ->setEffort(Effort::High)    // reasoning effort, or a thinking budget on Gemini and Claude before 4.6
    ->setOptions([               // raw provider parameters, keyed by adapter name
        'anthropic' => ['metadata' => ['user_id' => 'user-1']],
        'openai' => ['service_tier' => 'flex'],
    ]);
```

Settings left unset use the provider's default.

### Models without an agent

Adapters implement `Model`, so you can call them directly with a `Request`:

```php
use Utopia\Agents\Adapters\Anthropic;
use Utopia\Agents\Message;
use Utopia\Agents\Request;

$model = new Anthropic(getenv('ANTHROPIC_API_KEY'), 'claude-haiku-5-5');
$response = $model->generate(new Request([Message::user('Hi')], maxTokens: 100));
$response->parts;   // list of Part
$response->finish;  // FinishReason
$response->usage;   // Usage
```

### Errors and retries

Every exception extends `Utopia\Agents\Exception`:

| Exception | When |
|---|---|
| `Exception\Api` | The provider returned an error; has `status`, `type`, `retryable`, `retryAfter`, `body` |
| `Exception\RateLimit` | HTTP 429 |
| `Exception\Authentication` | HTTP 401 or 403 |
| `Exception\Output` | Structured output is not valid JSON or does not match the schema |
| `Exception\Unsupported` | The adapter cannot send a content type |

Failed requests are retried twice with exponential backoff by default. Streams are only retried before their first event.

```php
$model->setRetries(5, delay: 1000)->setTimeout(120000);
```

### Embeddings

```php
use Utopia\Agents\Adapters\Appwrite;
use Utopia\Agents\Adapters\Ollama;
use Utopia\Agents\Adapters\OpenAI\Embedder;

$embedder = new Embedder($key, 'text-embedding-3-small');
// new Ollama('embeddinggemma');
// new Appwrite(Appwrite::MODEL_NOMIC_EMBED_TEXT);

$embeddings = $embedder->embed(['first text', 'second text']);
$embeddings->vectors;        // one list<float> per text
$embeddings->tokens;         // when the provider reports it
$embedder->getDimension();   // vector length
```

## Upgrading from 0.x

The API was redesigned around messages with typed parts, a tool loop and a structured result:

- `Conversation::message($role, new Message('...'))->send()` becomes `$agent->generate('...')`, or `$conversation->send('...')` to keep history. `Roles\User` and `Roles\Assistant` are replaced by `Message::user()` and `Message::assistant()`.
- `Agent::setDescription()` and `setInstructions(array)` become a single instructions string: `new Agent($model, 'instructions')`.
- Adapters take the model ID as their second argument and no longer carry `MODEL_*` constants. `maxTokens` and `temperature` moved from the adapter constructors to the agent.
- The streaming callback receives `Event` objects instead of strings: use `Events\TextDelta::$delta`.
- `send()` returns a `Result` with `text`, `output`, `finish`, `usage` and `steps` instead of a `Message`.
- `Schema` with `SchemaObject` becomes `Schema::object([...])`; the decoded value is in `Result::$output`.
- Token counters on adapters and conversations become `Usage` objects on `Response`, `Result` and `Conversation::getUsage()`.
- Embeddings moved from `Agent::embed()` and `bulkEmbed()` to `Embedder::embed(array $texts)`, which returns `Embeddings`. `getEmbeddingDimension()` is `getDimension()`.

## Tests

Unit tests need no network or keys:

```bash
composer test
```

The E2E suite in `tests/E2E` calls real providers. Each test is skipped unless its key is set (`LLM_KEY_OPENAI`, `LLM_KEY_ANTHROPIC`, `LLM_KEY_GEMINI`, `LLM_KEY_DEEPSEEK`, `LLM_KEY_XAI`, `LLM_KEY_OPENROUTER`, `LLM_KEY_PERPLEXITY`); `LLM_MODEL_<PROVIDER>` overrides the model.

```bash
vendor/bin/phpunit --testsuite e2e
```

## Security

We take security seriously. If you discover any security-related issues, please email security@appwrite.io instead of using the issue tracker.

## Contributing

All code contributions - including those of people having commit access - must go through a pull request and be approved by a core developer before being merged. This is to ensure a proper review of all the code.

We truly ❤️ pull requests! If you wish to help, you can learn more about how you can contribute to this project in the [contribution guide](CONTRIBUTING.md).

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
