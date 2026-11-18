<?php

namespace Utopia\Agents\Tests\Adapters;

use PHPUnit\Framework\TestCase;
use Utopia\Agents\Adapters\Appwrite;
use Utopia\Agents\Adapters\Ollama;
use Utopia\Agents\Adapters\OpenAI\Embedder;
use Utopia\Agents\Exception\Api;
use Utopia\Agents\Tests\Fakes\Client;

class EmbedderTest extends TestCase
{
    public function testAppwriteEmbedsBatch(): void
    {
        $client = (new Client())->queue(200, json_encode(['embeddings' => [[0.1, 0.2], [1, 2]], 'tokens' => 7, 'total_duration' => 1500]) ?: '');
        $embedder = new Appwrite(Appwrite::MODEL_ALL_MINILM, 'http://embed:3000/embed', client: $client);

        $result = $embedder->embed(['a', 'b']);

        $this->assertSame('http://embed:3000/embed', (string) $client->lastRequest()->getUri());
        $this->assertSame(['model' => 'all-minilm', 'texts' => ['a', 'b']], $client->lastPayload());
        $this->assertSame([[0.1, 0.2], [1.0, 2.0]], $result->vectors);
        $this->assertSame(7, $result->tokens);
        $this->assertSame(1500, $result->duration);
        $this->assertSame(384, $embedder->getDimension());
        $this->assertSame('appwrite', $embedder->getName());
    }

    public function testAppwriteRejectsUnknownModel(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Appwrite('unknown');
    }

    public function testAppwriteCountMismatchThrows(): void
    {
        $client = (new Client())->queue(200, json_encode(['embeddings' => [[0.1]]]) ?: '');

        $this->expectException(Api::class);

        (new Appwrite(client: $client))->embed(['a', 'b']);
    }

    public function testNonNumericComponentThrows(): void
    {
        $client = (new Client())->queue(200, json_encode(['embeddings' => [[0.1, null]]]) ?: '');

        $this->expectException(Api::class);
        $this->expectExceptionMessage('non-numeric component');

        (new Appwrite(client: $client))->embed(['a']);
    }

    public function testAppwriteServiceErrorThrows(): void
    {
        $client = (new Client())->queue(400, '{"error":"model not loaded"}');

        $this->expectException(Api::class);
        $this->expectExceptionMessage('model not loaded');

        (new Appwrite(client: $client))->setRetries(0)->embed(['a']);
    }

    public function testOllamaEmbedsBatchInOneRequest(): void
    {
        $client = (new Client())->queue(200, json_encode(['embeddings' => [[0.5], [0.6]], 'prompt_eval_count' => 4, 'total_duration' => 99]) ?: '');
        $embedder = new Ollama(client: $client);

        $result = $embedder->embed(['a', 'b']);

        $this->assertCount(1, $client->requests);
        $this->assertSame(['model' => 'embeddinggemma', 'input' => ['a', 'b']], $client->lastPayload());
        $this->assertSame([[0.5], [0.6]], $result->vectors);
        $this->assertSame(4, $result->tokens);
        $this->assertSame(768, $embedder->getDimension());
    }

    public function testOllamaNeedsDimensionForUnknownModel(): void
    {
        $this->assertSame(512, (new Ollama('custom', 512))->getDimension());

        $this->expectException(\InvalidArgumentException::class);
        new Ollama('custom');
    }

    public function testOpenAIOrdersByIndexAndSendsDimensions(): void
    {
        $client = (new Client())->queue(200, json_encode([
            'data' => [['index' => 1, 'embedding' => [0.2]], ['index' => 0, 'embedding' => [0.1]]],
            'usage' => ['prompt_tokens' => 3],
        ]) ?: '');
        $embedder = new Embedder('secret', 'text-embedding-3-large', 256, client: $client);

        $result = $embedder->embed(['a', 'b']);

        $this->assertSame('Bearer secret', $client->lastRequest()->getHeaderLine('authorization'));
        $this->assertSame(['model' => 'text-embedding-3-large', 'input' => ['a', 'b'], 'encoding_format' => 'float', 'dimensions' => 256], $client->lastPayload());
        $this->assertSame([[0.1], [0.2]], $result->vectors);
        $this->assertSame(3, $result->tokens);
        $this->assertSame(256, $embedder->getDimension());
        $this->assertSame(1536, (new Embedder('secret'))->getDimension());
    }
}
