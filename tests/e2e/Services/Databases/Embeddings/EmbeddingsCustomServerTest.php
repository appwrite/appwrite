<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Databases\Embeddings;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

final class EmbeddingsCustomServerTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    /**
     * Blank text carries no meaning, yet the model returns a vector for it that
     * still matches similarity queries. It must be rejected before the backend.
     */
    public function testCreateTextEmbeddingsBlankText(): void
    {
        /**
         * Test for FAILURE
         */
        $blank = [' ', "\n\t", "\u{00A0}", "\u{200B}", "\u{3000}"];

        foreach ($blank as $text) {
            $response = $this->createTextEmbeddings([$text]);
            $this->assertEquals(400, $response['headers']['status-code']);
        }

        // One blank entry rejects the whole request, even next to valid text.
        $mixed = $this->createTextEmbeddings(['hello world', ' ']);
        $this->assertEquals(400, $mixed['headers']['status-code']);
    }

    public function testCreateTextEmbeddings(): void
    {
        $texts = ['hello world', 'second sentence'];

        /**
         * Test for SUCCESS
         */
        $this->assertEventually(function () use ($texts) {
            $response = $this->createTextEmbeddings($texts, ['model' => 'nomic-embed-text']);

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals(\count($texts), $response['body']['total']);
            $this->assertCount(\count($texts), $response['body']['embeddings']);

            foreach ($response['body']['embeddings'] as $embedding) {
                $this->assertEquals('nomic-embed-text', $embedding['model']);
                $this->assertEquals(768, $embedding['dimension']);
                $this->assertCount(768, $embedding['embedding']);
                $this->assertEmpty($embedding['error']);
            }
        }, 10000, 500);
    }

    public function testCreateTextEmbeddingsLimit(): void
    {
        /**
         * Test for FAILURE
         */
        // The default batch limit is 100 texts, so 101 must be refused.
        $response = $this->createTextEmbeddings(\array_fill(0, 101, 'hello'));
        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testCreateTextEmbeddingsUnavailable(): void
    {
        // CI loads only nomic-embed-text, so every text sent to all-minilm fails.
        $response = $this->createTextEmbeddings(['hello'], ['model' => 'all-minilm']);

        if ($response['headers']['status-code'] === 200) {
            $this->markTestSkipped('all-minilm is loaded on this stack, so no model fails.');
        }

        /**
         * Test for FAILURE
         */
        $this->assertEquals(503, $response['headers']['status-code']);
        $this->assertEquals('general_embedding_unavailable', $response['body']['type']);
    }

    /**
     * @param array<string> $texts
     * @param array<string, mixed> $params
     */
    private function createTextEmbeddings(array $texts, array $params = []): array
    {
        return $this->client->call(Client::METHOD_POST, '/embeddings/text', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), \array_merge(['texts' => $texts], $params));
    }
}