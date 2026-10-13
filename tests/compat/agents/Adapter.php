<?php

declare(strict_types=1);

namespace Tests\Compat\Agents;

require_once __DIR__ . '/Clients.php';
require_once __DIR__ . '/Fake.php';

use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Tests\Compat\Task;
use Utopia\Agents\Adapter as AgentAdapter;
use Utopia\Agents\Adapters\Anthropic;
use Utopia\Agents\Adapters\Appwrite;
use Utopia\Agents\Adapters\Deepseek;
use Utopia\Agents\Adapters\Gemini;
use Utopia\Agents\Adapters\Ollama;
use Utopia\Agents\Adapters\OpenAI;
use Utopia\Agents\Adapters\OpenRouter;
use Utopia\Agents\Adapters\OpenRouter\Models;
use Utopia\Agents\Adapters\Perplexity;
use Utopia\Agents\Adapters\XAI;
use Utopia\Agents\Agent;
use Utopia\Agents\Conversation;
use Utopia\Agents\DiffCheck\DiffCheck;
use Utopia\Agents\DiffCheck\Options;
use Utopia\Agents\DiffCheck\Repository;
use Utopia\Agents\Message;
use Utopia\Agents\Role;
use Utopia\Agents\Roles\Assistant;
use Utopia\Agents\Roles\User;
use Utopia\Agents\Schema;
use Utopia\Agents\Schema\SchemaObject;

/**
 * Maps tests/compat/agents/spec.json operations onto utopia-php/agents. Glue
 * only: no logic. Providers are never called: requests go to a recording
 * queue client or to the `agents-http` fixture server.
 */
final class Adapter implements Base, Benchmarks
{
    /** @var \WeakMap<Conversation, \ArrayObject<int, string>>|null Tokens each conversation's listener received. */
    private static ?\WeakMap $listened = null;

    public function operations(): array
    {
        return [
            'message.inspect' => fn (array $a, Session $s) => self::encode(self::message($a['message'])),
            'message.with_role' => function (array $a, Session $s) {
                $message = self::message($a['message']);
                $copy = $message->withRole($a['role']);

                return ['copy' => self::encode($copy), 'original' => self::encode($message)];
            },
            'message.mime' => fn (array $a, Session $s) => (new Message($a['content']))->getMimeType(),
            'role.inspect' => function (array $a, Session $s) {
                $role = self::role($a);
                if (isset($a['rename'])) {
                    $role->setName($a['rename']);
                }

                return ['id' => $role->getId(), 'name' => $role->getName(), 'identifier' => $role->getIdentifier()];
            },
            'role.constants' => fn (array $a, Session $s) => ['assistant' => Role::ROLE_ASSISTANT, 'user' => Role::ROLE_USER],

            'schema_object.new' => fn (array $a, Session $s) => $s->handle(new SchemaObject($a['properties'] ?? [])),
            'schema_object.add' => function (array $a, Session $s) {
                $s->get($a['object'])->addProperty($a['name'], $a['property']);

                return null;
            },
            'schema_object.remove' => function (array $a, Session $s) {
                $s->get($a['object'])->removeProperty($a['name']);

                return null;
            },
            'schema_object.inspect' => function (array $a, Session $s) {
                $object = $s->get($a['object']);
                $out = ['properties' => $object->getProperties(), 'names' => $object->getNames()];
                if (isset($a['name'])) {
                    $out['property'] = $object->getProperty($a['name']);
                }

                return $out;
            },
            'schema_object.validate' => fn (array $a, Session $s) => $s->get($a['object'])->validateProperty($a['name'], $a['property']),
            'schema_object.valid_types' => fn (array $a, Session $s) => SchemaObject::getValidTypes(),
            'schema.new' => fn (array $a, Session $s) => $s->handle(new Schema($a['name'], $a['description'], $s->get($a['object']), $a['required'] ?? [])),
            'schema.inspect' => function (array $a, Session $s) {
                $schema = $s->get($a['schema']);

                return [
                    'name' => $schema->getName(),
                    'description' => $schema->getDescription(),
                    'required' => $schema->getRequired(),
                    'properties' => $schema->getProperties(),
                    'object' => $schema->getObject()->getProperties(),
                ];
            },
            'schema.to_json' => fn (array $a, Session $s) => $s->get($a['schema'])->toJson(),

            'adapter.new' => fn (array $a, Session $s) => $s->handle(self::adapter($a, $s)),
            'adapter.inspect' => fn (array $a, Session $s) => self::unfixture(self::inspect(self::adapterOf($a['adapter'], $s), $a['models'] ?? false), $s),
            'adapter.set_model' => function (array $a, Session $s) {
                self::adapterOf($a['adapter'], $s)->setModel($a['model']);

                return null;
            },
            'adapter.set' => function (array $a, Session $s) {
                $adapter = self::adapterOf($a['adapter'], $s);
                if (isset($a['timeout'])) {
                    $adapter->setTimeout($a['timeout']);
                }
                if (isset($a['maxTokens'])) {
                    $adapter->setMaxTokens($a['maxTokens']);
                }
                if (isset($a['temperature'])) {
                    $adapter->setTemperature((float) $a['temperature']);
                }
                if (isset($a['endpoint'])) {
                    $adapter->setEndpoint(self::fixture($a['endpoint'], $s));
                }

                return null;
            },
            'adapter.count' => function (array $a, Session $s) {
                $adapter = self::adapterOf($a['adapter'], $s);
                $adapter
                    ->countInputTokens($a['input'] ?? 0)
                    ->countOutputTokens($a['output'] ?? 0)
                    ->countCacheCreationInputTokens($a['cacheCreation'] ?? 0)
                    ->countCacheReadInputTokens($a['cacheRead'] ?? 0);

                return self::tokens($adapter);
            },
            'adapter.supports_attachment' => fn (array $a, Session $s) => self::adapterOf($a['adapter'], $s)->supportsAttachment(self::message($a['message'])),
            'adapter.embedding_dimension' => fn (array $a, Session $s) => self::adapterOf($a['adapter'], $s)->getEmbeddingDimension(),
            'adapter.agent' => fn (array $a, Session $s) => self::adapterOf($a['adapter'], $s)->getAgent() !== null,
            'adapter.send' => function (array $a, Session $s) {
                $adapter = self::adapterOf($a['adapter'], $s);
                $messages = array_map(self::message(...), $a['messages'] ?? []);
                $tokens = [];
                $listener = ($a['listen'] ?? false) ? function (string $token) use (&$tokens): void {
                    $tokens[] = $token;
                } : null;
                try {
                    $message = $adapter->send($messages, $listener);
                } catch (\Throwable $error) {
                    if (!($a['code'] ?? false)) {
                        throw $error;
                    }

                    return ['class' => $error::class, 'message' => self::unfixture($error->getMessage(), $s), 'code' => $error->getCode(), 'tokens' => $tokens];
                }
                $out = ['content' => $message->getContent(), 'role' => $message->getRole()];
                if ($listener !== null) {
                    $out['tokens'] = $tokens;
                }

                return $out;
            },
            'adapter.embed' => fn (array $a, Session $s) => self::adapterOf($a['adapter'], $s)->embed($a['text']),
            'adapter.bulk_embed' => fn (array $a, Session $s) => self::adapterOf($a['adapter'], $s)->bulkEmbed($a['texts']),
            'adapter.requests' => fn (array $a, Session $s) => self::unfixture(self::client($a['adapter'], $s)->recorded(), $s),
            'fake.inspect' => function (array $a, Session $s) {
                $fake = self::adapterOf($a['adapter'], $s);
                if (!$fake instanceof Fake) {
                    throw new Fault('not a fake adapter');
                }
                $last = $fake->lastMessage === null ? null : self::encode($fake->lastMessage);
                if ($last !== null) {
                    $last['content'] = self::untemp($last['content']);
                }

                return ['sendCalls' => $fake->sendCalls, 'last' => $last];
            },

            'agent.new' => fn (array $a, Session $s) => $s->handle(new Agent(self::adapterOf($a['adapter'], $s))),
            'agent.set_description' => function (array $a, Session $s) {
                self::agentOf($a['agent'], $s)->setDescription($a['description']);

                return null;
            },
            'agent.set_instructions' => function (array $a, Session $s) {
                $instructions = [];
                foreach ($a['instructions'] as [$name, $content]) {
                    $instructions[$name] = $content;
                }
                self::agentOf($a['agent'], $s)->setInstructions($instructions);

                return null;
            },
            'agent.add_instruction' => function (array $a, Session $s) {
                self::agentOf($a['agent'], $s)->addInstruction($a['name'], $a['content']);

                return null;
            },
            'agent.set_schema' => function (array $a, Session $s) {
                self::agentOf($a['agent'], $s)->setSchema($s->get($a['schema']));

                return null;
            },
            'agent.inspect' => function (array $a, Session $s) {
                $agent = self::agentOf($a['agent'], $s);
                $instructions = [];
                foreach ($agent->getInstructions() as $name => $content) {
                    $instructions[] = [(string) $name, $content];
                }

                return [
                    'description' => $agent->getDescription(),
                    'instructions' => $instructions,
                    'schema' => $agent->getSchema()?->getName(),
                    'adapter' => $agent->getAdapter()->getName(),
                ];
            },
            'agent.embed' => fn (array $a, Session $s) => self::agentOf($a['agent'], $s)->embed($a['text']),
            'agent.bulk_embed' => fn (array $a, Session $s) => self::agentOf($a['agent'], $s)->bulkEmbed($a['texts']),

            'conversation.new' => fn (array $a, Session $s) => $s->handle(new Conversation(self::agentOf($a['agent'], $s))),
            'conversation.listen' => function (array $a, Session $s) {
                $conversation = $s->get($a['conversation']);
                $tokens = new \ArrayObject();
                self::$listened ??= new \WeakMap();
                self::$listened[$conversation] = $tokens;
                $conversation->listen(function (string $token) use ($tokens): void {
                    $tokens[] = $token;
                });

                return null;
            },
            'conversation.message' => function (array $a, Session $s) {
                $s->get($a['conversation'])->message(
                    self::role($a['from']),
                    self::message($a['message']),
                    array_map(self::message(...), $a['attachments'] ?? []),
                );

                return null;
            },
            'conversation.send' => function (array $a, Session $s) {
                $message = $s->get($a['conversation'])->send();

                return ['content' => $message->getContent(), 'role' => $message->getRole()];
            },
            'conversation.count' => function (array $a, Session $s) {
                $conversation = $s->get($a['conversation']);
                $conversation
                    ->countInputTokens($a['input'] ?? 0)
                    ->countOutputTokens($a['output'] ?? 0)
                    ->countCacheCreationInputTokens($a['cacheCreation'] ?? 0)
                    ->countCacheReadInputTokens($a['cacheRead'] ?? 0);

                return self::tokens($conversation);
            },
            'conversation.inspect' => function (array $a, Session $s) {
                $conversation = $s->get($a['conversation']);

                return [
                    'messages' => array_map(self::encode(...), $conversation->getMessages()),
                    'tokens' => self::tokens($conversation),
                    'listened' => isset(self::$listened[$conversation]) ? self::$listened[$conversation]->getArrayCopy() : null,
                ];
            },

            'fixture.queue' => function (array $a, Session $s) {
                self::fixtureCall('POST', '/queue/' . $s->ns, json_encode(self::bytes($a['responses']), JSON_THROW_ON_ERROR), $s);

                return null;
            },
            'fixture.requests' => function (array $a, Session $s) {
                $recorded = json_decode(self::fixtureCall('GET', '/requests/' . $s->ns, '', $s), true, 512, JSON_THROW_ON_ERROR);
                $host = (string) parse_url($s->service('agents-http'), PHP_URL_HOST) . ':' . (string) parse_url($s->service('agents-http'), PHP_URL_PORT);
                foreach ($recorded as &$request) {
                    foreach ($request['headers'] as &$header) {
                        if (strcasecmp($header[0], 'Host') === 0) {
                            $header[1] = str_replace($host, '${fixture}', $header[1]);
                        }
                    }
                    unset($header);
                    if (\is_array($request['body'])) {
                        $request['body'] = (string) base64_decode($request['body']['$bytes'], true);
                    }
                    $request['body'] = self::untemp($request['body']);
                }
                unset($request);

                return $recorded;
            },

            'fs.write' => function (array $a, Session $s) {
                foreach ($a['files'] as $path => $content) {
                    $file = $a['dir'] . '/' . $path;
                    if (!is_dir(\dirname($file))) {
                        mkdir(\dirname($file), 0777, true);
                    }
                    file_put_contents($file, $content);
                }

                return null;
            },
            'fs.git' => fn (array $a, Session $s) => self::git($a),
            'diff.run' => function (array $a, Session $s) {
                $options = self::options($a['options'] ?? [], $s);
                // git abbreviates hashes by the size of the repository it runs in:
                // run outside any, in the run's directory, as the Rust driver does.
                $cwd = (string) getcwd();
                $dir = '/tmp/compat-fs/' . $s->ns;
                @mkdir($dir, 0777, true);
                chdir($dir);
                try {
                    return (new DiffCheck())->run(
                        self::runner($a['runner'], $s),
                        self::source($a['base']),
                        self::source($a['target']),
                        $a['prompt'],
                        $options,
                    );
                } finally {
                    chdir($cwd);
                }
            },
            'diff.options' => fn (array $a, Session $s) => self::describe(self::options($a['calls'] ?? [], $s)),
            'diff.repository' => function (array $a, Session $s) {
                $repository = match ($a['how']) {
                    'new' => new Repository($a['source'], $a['remote'] ?? false, $a['ref'] ?? null),
                    'local' => Repository::local($a['source'], $a['ref'] ?? null),
                    'remote' => Repository::remote($a['source'], $a['ref'] ?? null),
                    'from' => Repository::from($a['source']),
                    default => throw new Fault('unknown how'),
                };

                return ['source' => $repository->getSource(), 'remote' => $repository->isRemote(), 'ref' => $repository->getRef()];
            },

            'stream.parse' => fn (array $a, Session $s) => self::parse($a),

            'sync.run' => fn (array $a, Session $s) => self::sync($a, $s),
            'data.digest' => function (array $a, Session $s) {
                $constants = (new \ReflectionClass(Models::class))->getConstants();
                $models = $constants['MODELS'];
                unset($constants['MODELS']);

                return self::digest($constants, $models);
            },
        ];
    }

    public function benchmarks(): array
    {
        $events = '';
        for ($i = 0; $i < 50; $i++) {
            $events .= 'data: ' . json_encode(['choices' => [['delta' => ['content' => 'token ' . $i . ' ']]]]) . "\n\n";
        }
        $events .= "data: [DONE]\n\n";
        $chunks = str_split($events, 97);

        $stream = static function (string $kind) use ($chunks): \Closure {
            $client = new BenchClient($chunks);
            $adapter = match ($kind) {
                'anthropic' => new Anthropic('test-api-key', client: $client),
                default => new OpenAI('test-api-key', OpenAI::MODEL_GPT_4O, client: $client),
            };
            new Agent($adapter);
            $messages = [new Message('Hello')];

            return static fn () => $adapter->send($messages);
        };

        $anthropic = '';
        for ($i = 0; $i < 50; $i++) {
            $anthropic .= 'data: ' . json_encode(['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => 'token ' . $i . ' ']]) . "\n\n";
        }

        return [
            'openai_stream' => ['Send a conversation and parse a 50-token OpenAI stream cut in 97-byte chunks (in-memory client)', fn (Session $s) => $stream('openai')],
            'anthropic_stream' => ['Send a conversation and parse a 50-token Anthropic stream cut in 97-byte chunks (in-memory client)', function (Session $s) use ($anthropic) {
                $client = new BenchClient(str_split($anthropic, 97));
                $adapter = new Anthropic('test-api-key', client: $client);
                new Agent($adapter);
                $messages = [new Message('Hello')];

                return static fn () => $adapter->send($messages);
            }],
            'conversation_message' => ['Add a message with a PNG attachment to a conversation (attachment validation and MIME detection)', function (Session $s) {
                $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVQYV2NgYAAAAAMAAWgmWQ0AAAAASUVORK5CYII=', true);
                $agent = new Agent(new OpenAI('test-api-key'));
                $user = new User('user-1');

                return static function () use ($agent, $user, $png) {
                    (new Conversation($agent))->message($user, new Message('What is in this image?'), [new Message($png)]);
                };
            }],
            'schema_to_json' => ['Describe a three-property schema as JSON', function (Session $s) {
                $object = new SchemaObject([
                    'id' => ['type' => 'string', 'description' => 'The ID of the user'],
                    'name' => ['type' => 'string', 'description' => 'The name of the user'],
                    'age' => ['type' => 'integer', 'description' => 'The age of the user'],
                ]);
                $schema = new Schema('User', 'A user', $object, ['id', 'name']);

                return static fn () => $schema->toJson();
            }],
            'fixture_send' => ['Send a conversation to the fixture server and parse its streamed answer (one HTTP round trip)', function (Session $s) {
                $adapter = new OpenAI('test-api-key', OpenAI::MODEL_GPT_4O, endpoint: $s->service('agents-http') . '/serve/bench-php');
                new Agent($adapter);
                $messages = [new Message('Hello')];
                $chunk = 'data: ' . json_encode(['choices' => [['delta' => ['content' => 'Hi']]]]) . "\n\ndata: [DONE]\n\n";

                return static function () use ($adapter, $messages, $s, $chunk) {
                    self::fixtureCall('POST', '/queue/bench-php', json_encode([['status' => 200, 'chunks' => [$chunk], 'delay' => 0]], JSON_THROW_ON_ERROR), $s);
                    $adapter->send($messages);
                };
            }],
        ];
    }

    /**
     * A message from its description: a string (its content), or
     * {content, role?, attachments?, add?, set?}, `add` attached one by one
     * after construction and `set` replacing them.
     */
    private static function message(mixed $spec): Message
    {
        if (\is_string($spec)) {
            return new Message($spec);
        }
        $message = new Message($spec['content'], $spec['role'] ?? null, array_map(self::message(...), $spec['attachments'] ?? []));
        foreach ($spec['add'] ?? [] as $attachment) {
            $message->addAttachment(self::message($attachment));
        }
        if (\array_key_exists('set', $spec)) {
            $message->setAttachments(array_map(self::message(...), $spec['set']));
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    private static function encode(Message $message): array
    {
        return [
            'role' => $message->getRole(),
            'content' => $message->getContent(),
            'mime' => $message->getMimeType(),
            'hasAttachments' => $message->hasAttachments(),
            'attachments' => array_map(self::encode(...), $message->getAttachments()),
        ];
    }

    private static function role(array $a): Role
    {
        return match ($a['kind']) {
            'user' => isset($a['name']) ? new User($a['id'], $a['name']) : new User($a['id']),
            'assistant' => isset($a['name']) ? new Assistant($a['id'], $a['name']) : new Assistant($a['id']),
            default => throw new Fault('unknown role kind'),
        };
    }

    /**
     * `${fixture}` in an endpoint: the fixture server's serve route for this run.
     */
    private static function fixture(string $value, Session $s): string
    {
        return str_replace('${fixture}', $s->service('agents-http') . '/serve/' . $s->ns, $value);
    }

    /**
     * The fixture server's serve route for this run, back to `${fixture}`.
     */
    private static function unfixture(mixed $value, Session $s): mixed
    {
        $base = $s->service('agents-http') . '/serve/' . $s->ns;
        if (\is_string($value)) {
            return str_replace($base, '${fixture}', $value);
        }
        if (\is_array($value)) {
            return array_map(fn ($v) => self::unfixture($v, $s), $value);
        }

        return $value;
    }

    private static function adapter(array $a, Session $s): AgentAdapter
    {
        $args = $a['args'] ?? [];
        $timeout = $args['timeout'] ?? 90000;
        $client = match ($a['client'] ?? null) {
            null => null,
            'forward' => new Forward($s->service('agents-http') . '/serve/' . $s->ns, $timeout),
            default => new Queue(self::bytes($a['client']['queue'] ?? [])),
        };
        $endpoint = isset($args['endpoint']) ? self::fixture($args['endpoint'], $s) : null;
        $key = $args['apiKey'] ?? 'test-api-key';
        $tokens = $args['maxTokens'] ?? 1024;
        $temperature = (float) ($args['temperature'] ?? 1.0);

        return match ($a['kind']) {
            'openai' => new OpenAI($key, $args['model'] ?? OpenAI::MODEL_O3_MINI, $tokens, $temperature, $endpoint, $timeout, $client),
            'anthropic' => new Anthropic($key, $args['model'] ?? Anthropic::MODEL_CLAUDE_3_HAIKU, $tokens, $temperature, $timeout, $client),
            'gemini' => new Gemini($key, $args['model'] ?? Gemini::MODEL_GEMINI_2_0_FLASH, $tokens, $temperature, $endpoint, $timeout, $client),
            'deepseek' => new Deepseek($key, $args['model'] ?? Deepseek::MODEL_DEEPSEEK_CHAT, $tokens, $temperature, $timeout, $client),
            'openrouter' => new OpenRouter($key, $args['model'] ?? Models::MODEL_OPENAI_GPT_4O, $tokens, $temperature, $endpoint, $timeout, $args['httpReferer'] ?? null, $args['xTitle'] ?? null, $client),
            'perplexity' => new Perplexity($key, $args['model'] ?? Perplexity::MODEL_SONAR, $tokens, $temperature, $endpoint, $timeout, $client),
            'xai' => new XAI($key, $args['model'] ?? XAI::MODEL_GROK_3_MINI, $tokens, $temperature, $endpoint, $timeout, $client),
            'ollama' => new Ollama($args['model'] ?? Ollama::MODEL_EMBEDDING_GEMMA, $timeout, $client),
            'appwrite' => new Appwrite($args['model'] ?? Appwrite::MODEL_NOMIC_EMBED_TEXT, $timeout, $client),
            'fake' => new Fake($a['fake'] ?? []),
            default => throw new Fault('unknown adapter kind ' . $a['kind']),
        };
    }

    /**
     * `{"$bytes"}` tags inside queued responses, decoded by the Codec already,
     * back to tags for the fixture server; strings for the queue client.
     */
    private static function bytes(mixed $value): mixed
    {
        if (\is_string($value)) {
            return mb_check_encoding($value, 'UTF-8') ? $value : ['$bytes' => base64_encode($value)];
        }
        if (\is_array($value)) {
            return array_map(self::bytes(...), $value);
        }

        return $value;
    }

    /**
     * The adapter of a handle to an adapter, agent or conversation.
     */
    private static function adapterOf(mixed $handle, Session $s): AgentAdapter
    {
        $object = $s->get($handle);

        return match (true) {
            $object instanceof AgentAdapter => $object,
            $object instanceof Agent => $object->getAdapter(),
            $object instanceof Conversation => $object->getAgent()->getAdapter(),
            default => throw new Fault('not an adapter'),
        };
    }

    private static function agentOf(mixed $handle, Session $s): Agent
    {
        $object = $s->get($handle);

        return match (true) {
            $object instanceof Agent => $object,
            $object instanceof Conversation => $object->getAgent(),
            default => throw new Fault('not an agent'),
        };
    }

    private static function client(mixed $handle, Session $s): Recording
    {
        $adapter = self::adapterOf($handle, $s);
        $client = (new \ReflectionProperty(AgentAdapter::class, 'client'))->getValue($adapter);
        if (!$client instanceof Recording) {
            throw new Fault('the adapter has no recording client');
        }

        return $client;
    }

    /**
     * @return array<string, mixed>
     */
    private static function inspect(AgentAdapter $adapter, bool $models): array
    {
        $out = [
            'name' => $adapter->getName(),
            'model' => $adapter->getModel(),
            'schema' => $adapter->isSchemaSupported(),
            'embeddings' => $adapter->getSupportForEmbeddings(),
            'timeout' => $adapter->getTimeout(),
            'attachments' => [
                'supported' => $adapter->supportsAttachments(),
                'maxPerMessage' => $adapter->getMaxAttachmentsPerMessage(),
                'maxBytes' => $adapter->getMaxAttachmentBytes(),
                'maxTotalBytes' => $adapter->getMaxTotalAttachmentBytes(),
                'mimeTypes' => $adapter->getAllowedAttachmentMimeTypes(),
            ],
            'tokens' => self::tokens($adapter),
        ];
        $out['modelCount'] = \count($adapter->getModels());
        $out['modelListed'] = \in_array($adapter->getModel(), $adapter->getModels(), true);
        if ($models) {
            $out['models'] = $adapter->getModels();
        }
        if (method_exists($adapter, 'getEndpoint')) {
            $out['endpoint'] = $adapter->getEndpoint();
        }

        return $out;
    }

    /**
     * @return array<string, int>
     */
    private static function tokens(AgentAdapter|Conversation $counted): array
    {
        return [
            'input' => $counted->getInputTokens(),
            'output' => $counted->getOutputTokens(),
            'cacheCreation' => $counted->getCacheCreationInputTokens(),
            'cacheRead' => $counted->getCacheReadInputTokens(),
            'total' => $counted->getTotalTokens(),
        ];
    }

    private static function fixtureCall(string $method, string $path, string $body, Session $s): string
    {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => 'Content-Type: application/json',
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 30,
        ]]);
        $response = file_get_contents($s->service('agents-http') . $path, false, $context);
        if ($response === false) {
            throw new Fault('the agents-http fixture server did not answer');
        }

        return $response;
    }

    /**
     * Prompts DiffCheck built name its temporary copies with random suffixes.
     */
    private static function untemp(string $text): string
    {
        return (string) preg_replace('~utopia-diff-check-(base|target)-[0-9a-f]{16}~', 'utopia-diff-check-$1-<random>', $text);
    }

    /**
     * A Git repository in `dir`: `git init`, then one commit per entry of
     * `commits` ({files, message, tag?, branch?}) with a fixed author and date.
     */
    private static function git(array $a): mixed
    {
        $dir = $a['dir'];
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $env = [
            'GIT_AUTHOR_NAME' => 'Compat', 'GIT_AUTHOR_EMAIL' => 'compat@example.test', 'GIT_AUTHOR_DATE' => '2024-01-01T00:00:00Z',
            'GIT_COMMITTER_NAME' => 'Compat', 'GIT_COMMITTER_EMAIL' => 'compat@example.test', 'GIT_COMMITTER_DATE' => '2024-01-01T00:00:00Z',
            'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1', 'HOME' => '/tmp',
        ];
        $run = static function (array $command) use ($dir, $env): void {
            $process = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $dir, $env);
            if (!\is_resource($process) || proc_close($process) !== 0) {
                throw new Fault('git failed: ' . implode(' ', $command));
            }
        };
        $run(['git', 'init', '-q', '-b', 'main']);
        foreach ($a['commits'] as $commit) {
            if (isset($commit['branch'])) {
                $run(['git', 'checkout', '-q', '-B', $commit['branch']]);
            }
            foreach ($commit['files'] as $path => $content) {
                $file = $dir . '/' . $path;
                if (!is_dir(\dirname($file))) {
                    mkdir(\dirname($file), 0777, true);
                }
                file_put_contents($file, $content);
            }
            $run(['git', 'add', '-A']);
            $run(['git', 'commit', '-q', '-m', $commit['message']]);
            if (isset($commit['tag'])) {
                $run(['git', 'tag', $commit['tag']]);
            }
        }

        return null;
    }

    private static function source(mixed $source): Repository|string
    {
        if (\is_string($source)) {
            return $source;
        }

        return new Repository($source['source'], $source['remote'] ?? false, $source['ref'] ?? null);
    }

    private static function runner(mixed $handle, Session $s): Agent|AgentAdapter
    {
        $object = $s->get($handle);

        return $object instanceof Agent ? $object : self::adapterOf($handle, $s);
    }

    /**
     * Options after the setter calls [[method, argument], ...] (`schema` takes a
     * schema handle or null).
     */
    private static function options(array $calls, Session $s): Options
    {
        $options = new Options();
        foreach ($calls as [$method, $argument]) {
            match ($method) {
                'setMaxDiffLines' => $options->setMaxDiffLines($argument),
                'setExcludePaths' => $options->setExcludePaths($argument),
                'addExcludePath' => $options->addExcludePath($argument),
                'setIgnoreAllSpace' => $options->setIgnoreAllSpace($argument),
                'setIgnoreBlankLines' => $options->setIgnoreBlankLines($argument),
                'setSchema' => $options->setSchema($argument === null ? null : $s->get($argument)),
                'setInstructions' => $options->setInstructions(array_column($argument, 1, 0)),
                'addInstruction' => $options->addInstruction($argument[0], $argument[1]),
                'setDescription' => $options->setDescription($argument),
                'setUserId' => $options->setUserId($argument),
                'setTrimResponse' => $options->setTrimResponse($argument),
                default => throw new Fault("unknown option {$method}"),
            };
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    private static function describe(Options $options): array
    {
        $instructions = [];
        foreach ($options->getInstructions() as $name => $content) {
            $instructions[] = [(string) $name, $content];
        }

        return [
            'maxDiffLines' => $options->getMaxDiffLines(),
            'excludePaths' => $options->getExcludePaths(),
            'ignoreAllSpace' => $options->getIgnoreAllSpace(),
            'ignoreBlankLines' => $options->getIgnoreBlankLines(),
            'schema' => $options->getSchema()?->getName(),
            'instructions' => $instructions,
            'description' => $options->getDescription(),
            'userId' => $options->getUserId(),
            'trimResponse' => $options->getTrimResponse(),
        ];
    }

    /**
     * A streamed answer of `kind` whose body is `events` cut at the byte
     * offsets `cuts` (sorted, deduplicated), sent through the queue client by
     * an agent (with `schema` for Deepseek's JSON mode): the answer and tokens
     * the listener got, or the error, and the tokens the adapter counted.
     *
     * @return array<string, mixed>
     */
    private static function parse(array $a): array
    {
        $events = $a['events'];
        $cuts = array_values(array_unique(array_filter($a['cuts'] ?? [], fn ($c) => $c > 0 && $c < \strlen($events))));
        sort($cuts);
        $chunks = [];
        $start = 0;
        foreach ([...$cuts, \strlen($events)] as $cut) {
            $chunks[] = substr($events, $start, $cut - $start);
            $start = $cut;
        }
        $client = new Queue([['status' => $a['status'] ?? 200, 'chunks' => $chunks]]);
        $model = $a['model'] ?? null;
        $temperature = (float) ($a['temperature'] ?? 1.0);
        $tokens = $a['maxTokens'] ?? 1024;
        $adapter = match ($a['kind']) {
            'openai' => new OpenAI('test-api-key', $model ?? OpenAI::MODEL_O3_MINI, $tokens, $temperature, client: $client),
            'openrouter' => new OpenRouter('test-api-key', $model ?? Models::MODEL_OPENAI_GPT_4O, $tokens, $temperature, client: $client),
            'perplexity' => new Perplexity('test-api-key', $model ?? Perplexity::MODEL_SONAR, $tokens, $temperature, client: $client),
            'xai' => new XAI('test-api-key', $model ?? XAI::MODEL_GROK_3_MINI, $tokens, $temperature, client: $client),
            'anthropic' => new Anthropic('test-api-key', $model ?? Anthropic::MODEL_CLAUDE_3_HAIKU, $tokens, $temperature, client: $client),
            'gemini' => new Gemini('test-api-key', $model ?? Gemini::MODEL_GEMINI_2_0_FLASH, $tokens, $temperature, client: $client),
            'deepseek' => new Deepseek('test-api-key', $model ?? Deepseek::MODEL_DEEPSEEK_CHAT, $tokens, $temperature, client: $client),
            default => throw new Fault('unknown adapter kind'),
        };
        $agent = new Agent($adapter);
        $agent->setDescription($a['description'] ?? '');
        $instructions = [];
        foreach ($a['instructions'] ?? [] as [$name, $content]) {
            $instructions[$name] = $content;
        }
        $agent->setInstructions($instructions);
        $listened = [];
        try {
            $content = $adapter->send(array_map(self::message(...), $a['messages'] ?? ['hi']), function (string $token) use (&$listened): void {
                $listened[] = $token;
            })->getContent();
            $out = ['content' => $content];
        } catch (\Throwable $error) {
            $out = ['error' => ['class' => $error::class, 'message' => $error->getMessage(), 'code' => $error->getCode()]];
        }

        return $out + ['tokens' => $listened, 'usage' => self::tokens($adapter), 'request' => $client->recorded()[0]['body'] ?? null];
    }

    /**
     * scripts/sync-openrouter-models.php writing to the run's directory, its
     * catalog served by the fixture server (`catalog`: the response, queued;
     * absent, nothing is queued and the server answers 500), the API key
     * variables set to `key` (empty by default): {exit, stdout, stderr,
     * request, catalog}, `catalog` being what the written Models.php declares.
     *
     * @return array<string, mixed>
     */
    private static function sync(array $a, Session $s): array
    {
        $dir = '/tmp/compat-fs/' . $s->ns . '/agents';
        @mkdir($dir, 0777, true);
        $output = $dir . '/Models.php';
        if (isset($a['catalog'])) {
            self::fixtureCall('POST', '/queue/' . $s->ns, json_encode(self::bytes([$a['catalog']]), JSON_THROW_ON_ERROR), $s);
        }
        $endpoint = $s->service('agents-http') . '/serve/' . $s->ns . '/api/v1/models';
        $env = [
            'OPENROUTER_MODELS_ENDPOINT' => $endpoint,
            'OPENROUTER_API_KEY' => $a['key'] ?? '',
            'LLM_KEY_OPENROUTER' => $a['fallbackKey'] ?? '',
        ];
        $out = Task::run('packages/agents/scripts/sync-openrouter-models.php', [$output], $env);
        $clean = fn (string $text) => str_replace([$output, $s->service('agents-http')], ['${output}', '${fixture}'], $text);
        $requests = json_decode(self::fixtureCall('GET', '/requests/' . $s->ns, '', $s), true, 512, JSON_THROW_ON_ERROR);
        $request = null;
        if (isset($requests[0])) {
            $find = static function (string $name) use ($requests): ?string {
                foreach ($requests[0]['headers'] as [$header, $value]) {
                    if (strcasecmp($header, $name) === 0) {
                        return $value;
                    }
                }

                return null;
            };
            $request = ['method' => $requests[0]['method'], 'target' => $requests[0]['target'], 'accept' => $find('Accept'), 'authorization' => $find('Authorization')];
        }

        return [
            'exit' => $out['exit'],
            'stdout' => $clean($out['stdout']),
            'stderr' => $clean($out['stderr']),
            'request' => $request,
            'catalog' => self::catalog($output),
        ];
    }

    /**
     * What a generated Models.php declares, loaded under a namespace of its own:
     * {constants: [[name, id]], models}, or null when there is no file.
     *
     * @return array<string, mixed>|null
     */
    private static function catalog(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $namespace = 'Tests\\Compat\\Agents\\Generated\\N' . bin2hex(random_bytes(8));
        $code = str_replace('namespace Utopia\\Agents\\Adapters\\OpenRouter;', "namespace {$namespace};", (string) file_get_contents($file));
        $copy = $file . '.' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($copy, $code);
        include $copy;
        unlink($copy);
        $constants = (new \ReflectionClass($namespace . '\\Models'))->getConstants();
        $models = $constants['MODELS'];
        unset($constants['MODELS']);
        $pairs = [];
        foreach ($constants as $name => $id) {
            $pairs[] = [$name, $id];
        }

        return ['constants' => $pairs, 'models' => $models];
    }

    /**
     * The catalog as one line per constant then one per model: {constants,
     * models, sha256}.
     *
     * @param array<string, string> $constants
     * @param list<string> $models
     * @return array<string, mixed>
     */
    private static function digest(array $constants, array $models): array
    {
        $text = '';
        foreach ($constants as $name => $id) {
            $text .= "{$name} {$id}\n";
        }
        $text .= implode("\n", $models) . "\n";

        return ['constants' => \count($constants), 'models' => \count($models), 'sha256' => hash('sha256', $text)];
    }
}

/**
 * The in-memory client of the stream benchmarks: every request gets the same
 * chunks.
 */
final class BenchClient extends Recording
{
    /**
     * @param list<string> $chunks
     */
    public function __construct(private readonly array $chunks)
    {
    }

    public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        return new \Utopia\Psr7\Response(200);
    }

    public function stream(\Psr\Http\Message\RequestInterface $request, callable $sink): \Psr\Http\Message\ResponseInterface
    {
        foreach ($this->chunks as $chunk) {
            $sink($chunk);
        }

        return new \Utopia\Psr7\Response(200);
    }
}
