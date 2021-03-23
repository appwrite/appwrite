<?php

namespace Tests\E2E\Services\Functions;

use CURLFile;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;

class FunctionsCustomClientTest extends Scope
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
                'funcKey1' => 'funcValue1',
                'funcKey2' => 'funcValue2',
                'funcKey3' => 'funcValue3',
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

    public function testCreateExecution():array
    {
        /**
         * Test for SUCCESS
         */
        $function = $this->client->call(Client::METHOD_POST, '/functions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'name' => 'Test',
            'execute' => ['user:'.$this->getUser()['$id']],
            'env' => 'php-7.4',
            'vars' => [
                'funcKey1' => 'funcValue1',
                'funcKey2' => 'funcValue2',
                'funcKey3' => 'funcValue3',
            ],
            'events' => [
                'account.create',
                'account.delete',
            ],
            'schedule' => '* * * * *',
            'timeout' => 10,
        ]);

        $this->assertEquals(201, $function['headers']['status-code']);

        $tag = $this->client->call(Client::METHOD_POST, '/functions/'.$function['body']['$id'].'/tags', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'command' => 'php function.php',
            'code' => new CURLFile(realpath(__DIR__ . '/../../../resources/functions/php.tar.gz'), 'application/x-gzip', 'php-fx.tar.gz'),
        ]);

        $tagId = $tag['body']['$id'] ?? '';
        
        $this->assertEquals(201, $tag['headers']['status-code']);
        
        $function = $this->client->call(Client::METHOD_PATCH, '/functions/'.$function['body']['$id'].'/tag', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'tag' => $tagId,
        ]);
            
        $this->assertEquals(200, $function['headers']['status-code']);

        $execution = $this->client->call(Client::METHOD_POST, '/functions/'.$function['body']['$id'].'/executions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'async' => 1,
        ]);

        $this->assertEquals(401, $execution['headers']['status-code']);

        $execution = $this->client->call(Client::METHOD_POST, '/functions/'.$function['body']['$id'].'/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'async' => 1,
        ]);

        $executionId = $execution['body']['$id'] ?? '';

        $this->assertEquals(201, $execution['headers']['status-code']);
       
        $execution = $this->client->call(Client::METHOD_POST, '/functions/'.$function['body']['$id'].'/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'async' => 1,
        ]);

        $this->assertEquals(401, $execution['headers']['status-code']);
       
        return [];
    }
}