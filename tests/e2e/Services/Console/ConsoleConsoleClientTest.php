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
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(15, $response['body']);
        $this->assertIsString($response['body']['_APP_DOMAIN_TARGET_CNAME']);
        $this->assertIsString($response['body']['_APP_DOMAIN_TARGET_A']);
        $this->assertIsInt($response['body']['_APP_COMPUTE_BUILD_TIMEOUT']);
        $this->assertIsString($response['body']['_APP_DOMAIN_TARGET_AAAA']);
        $this->assertIsString($response['body']['_APP_DOMAIN_TARGET_CAA']);
        $this->assertIsInt($response['body']['_APP_STORAGE_LIMIT']);
        $this->assertIsInt($response['body']['_APP_COMPUTE_SIZE_LIMIT']);
        $this->assertIsBool($response['body']['_APP_DOMAIN_ENABLED']);
        $this->assertIsBool($response['body']['_APP_VCS_ENABLED']);
        $this->assertIsBool($response['body']['_APP_ASSISTANT_ENABLED']);
        $this->assertIsString($response['body']['_APP_DOMAIN_SITES']);
        $this->assertIsString($response['body']['_APP_DOMAIN_FUNCTIONS']);
        $this->assertIsString($response['body']['_APP_OPTIONS_FORCE_HTTPS']);
        $this->assertIsString($response['body']['_APP_DOMAINS_NAMESERVERS']);
        // When adding new keys, dont forget to update count a few lines above
    }
}
