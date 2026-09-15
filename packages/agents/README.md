# Utopia Agents

[![Build Status](https://travis-ci.org/utopia-php/agents.svg?branch=master)](https://travis-ci.org/utopia-php/agents)
![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/agents.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia Agents is a simple and lite library for building and managing AI agents in PHP applications. This library provides a collection of tools and utilities for creating, managing, and orchestrating AI agents with support for multiple AI providers. This library is maintained by the [Appwrite team](https://appwrite.io).

Although this library is part of the [Utopia Framework](https://github.com/utopia-php/framework) project it is dependency free and can be used as standalone with any other PHP project or framework.

## Getting Started

Install using composer:
```bash
composer require utopia-php/agents
```

## System Requirements

Utopia Framework requires PHP 8.0 or later. We recommend using the latest PHP version whenever possible.

## Features

- **Multiple AI Providers** - Support for OpenAI, Anthropic, Deepseek, Perplexity, XAI, Gemini, and OpenRouter APIs
- **Flexible Message Types** - Support for text and structured content in messages
- **Message Attachments** - Attach files (for example images) directly to conversation turns
- **Conversation Management** - Easy-to-use conversation handling between agents and users
- **Model Selection** - Choose from various AI models (GPT-4, Claude 3, Deepseek Chat, Sonar, Grok, etc.)
- **Parameter Control** - Fine-tune model behavior with temperature and token controls
- **Streaming Output** - Consume incremental model output through callback-driven Server-Sent Events (SSE) streams

## Usage

### Basic Example

```php
<?php

use Utopia\Agents\Agent;
use Utopia\Agents\Message;
use Utopia\Agents\Roles\User;
use Utopia\Agents\Conversation;
use Utopia\Agents\Adapters\OpenAI;

// Create an agent with OpenAI
$adapter = new OpenAI('your-api-key', OpenAI::MODEL_GPT_4_TURBO);
$agent = new Agent($adapter);

// Create a user
$user = new User('user-1', 'John');

// Start a conversation
$conversation = new Conversation($agent);
$conversation
    ->message($user, new Message('What is artificial intelligence?'))
    ->send();
```

### Using Different AI Providers

#### OpenAI

```php
use Utopia\Agents\Adapters\OpenAI;

$openai = new OpenAI(
    apiKey: 'your-api-key',
    model: OpenAI::MODEL_GPT_4_TURBO,
    maxTokens: 2048,
    temperature: 0.7
);
```

Available OpenAI Models:
- `MODEL_GPT_5_NANO`: GPT-5 Nano - Small GPT-5 variant optimized for low latency and cost-sensitive workloads
- `MODEL_GPT_4_5_PREVIEW`: GPT-4.5 Preview - OpenAI's most advanced model with enhanced reasoning, broader knowledge, and improved instruction following
- `MODEL_GPT_4_1`: GPT-4.1 - Advanced large language model with strong reasoning capabilities and improved context handling
- `MODEL_GPT_4O`: GPT-4o - Multimodal model optimized for both text and image processing with faster response times
- `MODEL_O4_MINI`: o4-mini - Compact version of GPT-4o offering good performance with higher throughput and lower latency
- `MODEL_O3`: o3 - Balanced model offering good performance for general language tasks with efficient resource usage
- `MODEL_O3_MINI`: o3-mini - Streamlined model optimized for speed and efficiency while maintaining good capabilities for routine tasks

#### Anthropic

```php
use Utopia\Agents\Adapters\Anthropic;

$anthropic = new Anthropic(
    apiKey: 'your-api-key',
    model: Anthropic::MODEL_CLAUDE_3_HAIKU,
    maxTokens: 2048,
    temperature: 0.7
);
```

Available Anthropic Models:
- `MODEL_CLAUDE_4_OPUS`: Flagship model with exceptional reasoning for the most demanding tasks
- `MODEL_CLAUDE_3_OPUS`: Premium model with superior performance on complex analysis and creative work
- `MODEL_CLAUDE_4_SONNET`: Intelligent and responsive model optimized for productivity workflows
- `MODEL_CLAUDE_3_7_SONNET`: Enhanced model with improved reasoning and coding capabilities
- `MODEL_CLAUDE_3_5_SONNET`: Versatile model balancing capability and speed for general use
- `MODEL_CLAUDE_3_5_HAIKU`: Ultra-fast model for quick responses and lightweight processing
- `MODEL_CLAUDE_3_HAIKU`: Rapid model designed for speed and efficiency on straightforward tasks

#### Deepseek

```php
use Utopia\Agents\Adapters\Deepseek;

$deepseek = new Deepseek(
    apiKey: 'your-api-key',
    model: Deepseek::MODEL_DEEPSEEK_CHAT,
    maxTokens: 2048,
    temperature: 0.7
);
```

Available Deepseek Models:
- `MODEL_DEEPSEEK_CHAT`: General-purpose chat model
- `MODEL_DEEPSEEK_CODER`: Specialized for code-related tasks

#### Perplexity

```php
use Utopia\Agents\Adapters\Perplexity;

$perplexity = new Perplexity(
    apiKey: 'your-api-key',
    model: Perplexity::MODEL_SONAR,
    maxTokens: 2048,
    temperature: 0.7
);
```

Available Perplexity Models:
- `MODEL_SONAR`: General-purpose search model
- `MODEL_SONAR_PRO`: Enhanced search model
- `MODEL_SONAR_DEEP_RESEARCH`: Advanced search model
- `MODEL_SONAR_REASONING`: Reasoning model
- `MODEL_SONAR_REASONING_PRO`: Enhanced reasoning model

#### XAI

```php
use Utopia\Agents\Adapters\XAI;

$xai = new XAI(
    apiKey: 'your-api-key',
    model: XAI::MODEL_GROK_3_MINI,
    maxTokens: 2048,
    temperature: 0.7
);
```

Available XAI Models:
- `MODEL_GROK_3`: Latest Grok model
- `MODEL_GROK_3_MINI`: Mini version of Grok model
- `MODEL_GROK_2_IMAGE`: Latest Grok model with image support

#### OpenRouter

```php
use Utopia\Agents\Adapters\OpenRouter;
use Utopia\Agents\Adapters\OpenRouter\Models as OpenRouterModels;

$openrouter = new OpenRouter(
    apiKey: 'your-api-key',
    model: OpenRouterModels::MODEL_OPENAI_GPT_4O,
    maxTokens: 2048,
    temperature: 0.7,
    httpReferer: 'https://your-app.example',
    xTitle: 'Your App Name'
);
```

- Named constants are provided for popular models from major providers (OpenAI, Anthropic, Google, Meta, DeepSeek, Mistral, xAI)
- `Models::MODELS` contains the full model catalog; the adapter defaults to `openai/gpt-4o`
- Arbitrary model IDs like `'openai/gpt-5-nano'` or `'anthropic/claude-sonnet-4'` are also accepted directly
- `httpReferer` and `xTitle` are optional and enable OpenRouter app attribution headers
- To re-sync constants from the live OpenRouter API, run `php scripts/sync-openrouter-models.php`

### Managing Conversations

```php
use Utopia\Agents\Roles\User;
use Utopia\Agents\Roles\Assistant;
use Utopia\Agents\Message;

// Create a conversation with system instructions
$agent = new Agent($adapter);
$agent->setInstructions([
    'description' => 'You are a helpful assistant that can answer questions and help with tasks.',
    'tone' => 'friendly and helpful',
]);

// Initialize roles
$user = new User('user-1'); 
$assistant = new Assistant('assistant-1');

$conversation = new Conversation($agent);
$conversation
    ->message($user, new Message('Hello!'))
    ->message($assistant, new Message('Hi! How can I help you today?'))
    ->message($user, new Message('What is the capital of France?'));

// Add a user message with attachments
$conversation->message(
    $user,
    new Message('Please summarize this screenshot'),
    [new Message($imageBinaryContent)]
);

// Send and get response
$response = $conversation->send();
```

### Streaming Responses (SSE)

The conversation layer supports incremental output streaming through `Conversation::listen(callable $listener)`.  
The callback receives each text delta as it arrives from the provider's SSE stream, while `send()` still returns the final aggregated `Message`.

#### Streaming in CLI / Worker Contexts

```php
use Utopia\Agents\Agent;
use Utopia\Agents\Conversation;
use Utopia\Agents\Adapters\OpenAI;
use Utopia\Agents\Message;
use Utopia\Agents\Roles\User;

$agent = new Agent(new OpenAI('your-api-key', OpenAI::MODEL_GPT_4O));
$conversation = new Conversation($agent);
$user = new User('user-1', 'John');

$conversation
    ->listen(function (string $chunk): void {
        echo $chunk; // render partial output as soon as it is received
    })
    ->message($user, new Message('Explain vector databases in one paragraph.'));

$final = $conversation->send(); // final, complete assistant message
```

#### Exposing Model Output as HTTP SSE

```php
use Utopia\Agents\Agent;
use Utopia\Agents\Conversation;
use Utopia\Agents\Adapters\OpenAI;
use Utopia\Agents\Message;
use Utopia\Agents\Roles\User;

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

$agent = new Agent(new OpenAI('your-api-key', OpenAI::MODEL_GPT_4O));
$conversation = new Conversation($agent);
$user = new User('user-1', 'John');

$conversation
    ->listen(function (string $chunk): void {
        // Send each token delta as an SSE frame
        echo 'data: '.json_encode(['delta' => $chunk], JSON_UNESCAPED_UNICODE)."\n\n";

        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        flush();
    })
    ->message($user, new Message('Write a short release note for today''s deployment.'));

$final = $conversation->send();

// Optional terminal event with complete text
echo 'event: done'."\n";
echo 'data: '.json_encode(['message' => $final->getContent()], JSON_UNESCAPED_UNICODE)."\n\n";
echo 'data: [DONE]'."\n\n";
flush();
```

#### Operational Notes

- Streaming is adapter-dependent and available for chat-capable providers that expose incremental output.
- The listener is optional; if omitted, responses are still collected and returned as a single final message.
- Keep callbacks non-blocking and lightweight to avoid slowing downstream token delivery.
- When serving SSE over HTTP, send `Content-Type: text/event-stream`, flush frequently, and disable intermediary buffering where applicable.
- Usage metrics (input/output tokens and cache counters, where supported) remain available after `send()` completes.

### Working with Messages

```php
use Utopia\Agents\Message;

// Message content is always text
$textMessage = new Message('Hello, how are you?');

// Attachments are binary payloads (for example images)
$imageMessage = new Message($imageBinaryContent);
$mimeType = $imageMessage->getMimeType(); // Get the MIME type of the image

// Attach image to a text prompt
$message = (new Message('Describe this image'))->addAttachment($imageMessage);
```

### Attachment Examples

```php
use Utopia\Agents\Conversation;
use Utopia\Agents\Message;
use Utopia\Agents\Roles\User;

$conversation = new Conversation($agent);
$user = new User('user-1', 'John');

// 1) Attach a single image in the same turn
$conversation->message(
    $user,
    new Message('What is shown here?'),
    [new Message(file_get_contents(__DIR__.'/images/screenshot.png'))]
);

// 2) Attach multiple images in one turn
$conversation->message(
    $user,
    new Message('Compare these two images and list differences.'),
    [
        new Message(file_get_contents(__DIR__.'/images/before.png')),
        new Message(file_get_contents(__DIR__.'/images/after.png')),
    ]
);

// 3) Build and reuse a message object with attachments
$prompt = (new Message('Extract visible text from this receipt'))
    ->addAttachment(new Message(file_get_contents(__DIR__.'/images/receipt.jpg')));

$conversation->message($user, $prompt);
```

### Attachment Limits and Validation

Attachment validation is enforced by default in `Conversation::message(...)`.
Guardrail values come from the selected adapter (not from conversation-level user configuration).

Default adapter guardrails:

- Max attachments per message: `10`
- Max binary size per attachment: `5_000_000` bytes (~5 MB)
- Max total attachment payload per turn: `20_000_000` bytes (~20 MB)
- MIME allowlist: `image/png`, `image/jpeg`, `image/webp`, `image/gif`
- Reject empty or unreadable payloads
- Adapter compatibility checks (attachment type must be supported by the selected adapter)

To customize limits, create an adapter subclass and override limit methods:

```php
<?php

use Utopia\Agents\Adapters\OpenAI;

class StrictOpenAI extends OpenAI
{
    public function getMaxAttachmentsPerMessage(): ?int
    {
        return 3;
    }

    public function getMaxAttachmentBytes(): ?int
    {
        return 2_000_000;
    }

    public function getMaxTotalAttachmentBytes(): ?int
    {
        return 6_000_000;
    }

    /**
     * @return list<string>|null
     */
    public function getAllowedAttachmentMimeTypes(): ?array
    {
        return ['image/png', 'image/jpeg'];
    }
}
```

## Schema and Schema Objects

You can use the `Schema` class to define a schema for a structured output. The `Schema` class utilizes `SchemaObject`s to define each property of the schema, following the [JSON Schema](https://json-schema.org/) format.

```php
use Utopia\Agents\Schema\Schema;
use Utopia\Agents\Schema\SchemaObject;

$object = new SchemaObject();
$object->addProperty('location', [
    'type' => SchemaObject::TYPE_STRING,
    'description' => 'The city and state, e.g. San Francisco, CA',
]);

$schema = new Schema(
    name: 'get_weather',
    description: 'Get the current weather in a given location in well structured JSON',
    object: $object,
    required: $object->getNames()
);

$agent->setSchema($schema);
```

## Tests

To run all unit tests, use the following Docker command:

```bash
docker compose exec tests vendor/bin/phpunit --configuration phpunit.xml tests
```

To run static code analysis, use the following Psalm command:

```bash
docker compose exec tests vendor/bin/psalm --show-info=true
```

## Security

We take security seriously. If you discover any security-related issues, please email security@appwrite.io instead of using the issue tracker.

## Contributing

All code contributions - including those of people having commit access - must go through a pull request and be approved by a core developer before being merged. This is to ensure a proper review of all the code.

We truly ❤️ pull requests! If you wish to help, you can learn more about how you can contribute to this project in the [contribution guide](CONTRIBUTING.md).

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php) 
