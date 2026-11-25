<?php

namespace Utopia\Agents\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Utopia\Agents\Adapters\Appwrite;
use Utopia\Agents\Adapters\Ollama;
use Utopia\Agents\Adapters\OpenAI\Embedder;
use Utopia\Agents\Embedder as Contract;

/**
 * Embedding services: the Appwrite and Ollama containers from the Appwrite
 * stack, and OpenAI when LLM_KEY_OPENAI is set.
 */
class EmbeddingsTest extends TestCase
{
    private function check(Contract $embedder): void
    {
        $texts = ['hi', 'Artificial intelligence maps text into vector spaces.', str_repeat('Repeated pattern. ', 100)];

        $result = $embedder->embed($texts);

        $this->assertCount(\count($texts), $result->vectors);
        foreach ($result->vectors as $vector) {
            $this->assertCount($embedder->getDimension(), $vector);
        }
    }

    public function testAppwrite(): void
    {
        $this->check(new Appwrite(endpoint: getenv('_APP_EMBEDDING_ENDPOINT') ?: null, timeout: 180000));
    }

    public function testOllama(): void
    {
        $this->check(new Ollama(timeout: 180000));
    }

    public function testOpenAI(): void
    {
        $key = getenv('LLM_KEY_OPENAI');
        if (! \is_string($key) || $key === '') {
            $this->markTestSkipped('LLM_KEY_OPENAI is not set');
        }

        $this->check(new Embedder($key, dimensions: 256));
    }
}
