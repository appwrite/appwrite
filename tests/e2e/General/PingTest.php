<?php

namespace Tests\E2E\General;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;

class PingTest extends Scope
{
    use ProjectCustom;
    use SideClient;

    public function testPing()
    {
        /**
         * Test for SUCCESS
         */
        // Without user session
        $response = $this->client->call(Client::METHOD_GET, '/ping', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('Pong!', $response['body']);

        // With user session
        $response = $this->client->call(Client::METHOD_GET, '/ping', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('Pong!', $response['body']);

        // With API key
        $response = $this->client->call(Client::METHOD_GET, '/ping', [
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('Pong!', $response['body']);

        /**
         * Test for FAILURE
         */
        // Fake project ID
        $response = $this->client->call(Client::METHOD_GET, '/ping', \array_merge([
            'x-appwrite-project' => 'fake-project-id',
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertNotContains('Pong!', $response['body']);
    }
}
