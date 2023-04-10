<?php

namespace Tests\E2E\Services\Console;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectConsole;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;

class ConsoleConsoleClientTest extends Scope
{
    use ConsoleBase;
    use ProjectConsole;
    use SideClient;

    public function testGetVariables(): void
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/console/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(4, $response['body']);
        $this->assertIsString($response['body']['_APP_DOMAIN_TARGET']);
        $this->assertIsInt($response['body']['_APP_STORAGE_LIMIT']);
        $this->assertIsInt($response['body']['_APP_FUNCTIONS_SIZE_LIMIT']);
        $this->assertIsString($response['body']['_APP_DOMAIN_TARGET']);
    }
}
