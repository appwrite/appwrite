<?php

namespace Tests\E2E\Services\Functions;

use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Client;
use Tests\E2E\Scopes\SideConsole;

class FunctionsConsoleClientTest extends Scope
{
    use ProjectCustom;
    use SideConsole;

    public function testCreateFunction(): array
    {
        $function = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'functionId' => 'unique()',
            'name' => 'Test',
            'execute' => ['user:' . $this->getUser()['$id']],
            'runtime' => 'php-8.0',
            'vars' => [
                'funcKey1' => 'funcValue1',
                'funcKey2' => 'funcValue2',
                'funcKey3' => 'funcValue3',
            ],
            'events' => [
                'account.create',
                'account.delete',
            ],
            'schedule' => '0 0 1 1 *',
            'timeout' => 10,
        ]);

        $this->assertEquals(201, $function['headers']['status-code']);

        return [
            'functionId' => $function['body']['$id']
        ];
    }

    /**
     * @depends testCreateFunction
     */
    public function testGetCollectionUsage(array $data)
    {
        /**
         * Test for FAILURE
         */

        $response = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '232h'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/functions/randomFunctionId/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '24h'
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);

        /**
         * Test for SUCCESS
         */

        $response = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '24h'
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertEquals(count($response['body']), 4);
        $this->assertEquals($response['body']['range'], '24h');
        $this->assertIsArray($response['body']['functionsExecutions']);
        $this->assertIsArray($response['body']['functionsFailures']);
        $this->assertIsArray($response['body']['functionsCompute']);
    }
}
