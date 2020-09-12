<?php

namespace Tests\E2E\Services\Functions;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;

class FunctionsConsoleClientTest extends Scope
{
    use FunctionsBase;
    use ProjectCustom;
    use SideClient;

    public function testCreate():array
    {
        /**
         * Test for SUCCESS
         */
        $response1 = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Test',
            'vars' => [
                'key1' => 'value1',
                'key2' => 'value2',
                'key3' => 'value3',
            ],
            'events' => [
                'account.create',
                'account.delete',
            ],
            'schedule' => '* * * * *',
            'timeout' => 10,
        ]);

        $this->assertEquals(401, $response1['headers']['status-code']);

        return [];
    }
}