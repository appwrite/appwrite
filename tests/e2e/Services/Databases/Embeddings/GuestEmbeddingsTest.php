<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Databases\Embeddings;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;

/**
 * Regression: embeddings generation is paid/backed compute with no per-resource
 * permission gate, so an unauthenticated (guest) caller must not reach it. Before
 * the fix the guests role held `embeddings.write`, letting anyone who knew a public
 * project ID burn the embedding backend. The scope check runs in the init hook, so
 * the refusal happens before any backend call.
 */
final class GuestEmbeddingsTest extends Scope
{
    use ProjectCustom;
    use SideClient;

    public function testGuestCannotGenerateEmbeddings(): void
    {
        // No session, no key: a pure guest request.
        $response = $this->client->call(Client::METHOD_POST, '/embeddings/text', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'texts' => ['regression check'],
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);
    }
}
