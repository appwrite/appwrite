<?php

namespace Tests\E2E\Services\Functions;

use CURLFile;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\Database;

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
            'functionId' => 'unique()',
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
            'schedule' => '0 0 1 1 *',
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
            'functionId' => 'unique()',
            'name' => 'Test',
            'execute' => ['user:'.$this->getUser()['$id']],
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

        $tag = $this->client->call(Client::METHOD_POST, '/functions/'.$function['body']['$id'].'/tags', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'entrypoint' => 'index.php',
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

        // Wait for tag to be built.
        sleep(5);

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

    public function testCreateCustomExecution():array
    {
        /**
         * Test for SUCCESS
         */
        $projectId = $this->getProject()['$id'];
        $apikey = $this->getProject()['apiKey'];

        $function = $this->client->call(Client::METHOD_POST, '/functions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ], [
            'functionId' => 'unique()',
            'name' => 'Test',
            'execute' => ['role:all'],
            'runtime' => 'php-8.0',
            'vars' => [
                'funcKey1' => 'funcValue1',
                'funcKey2' => 'funcValue2',
                'funcKey3' => 'funcValue3',
            ],
            'timeout' => 10,
        ]);

        $functionId = $function['body']['$id'] ?? '';

        $this->assertEquals(201, $function['headers']['status-code']);

        $tag = $this->client->call(Client::METHOD_POST, '/functions/'.$functionId.'/tags', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ], [
            'entrypoint' => 'index.php',
            'code' => new CURLFile(realpath(__DIR__ . '/../../../resources/functions/php-fn.tar.gz'), 'application/x-gzip', 'php-fx.tar.gz'), //different tarball names intentional
        ]);

        $tagId = $tag['body']['$id'] ?? '';

        $this->assertEquals(201, $tag['headers']['status-code']);

        $function = $this->client->call(Client::METHOD_PATCH, '/functions/'.$functionId.'/tag', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ], [
            'tag' => $tagId,
        ]);

        $this->assertEquals(200, $function['headers']['status-code']);

        // Wait for tag to be built.
        sleep(5);

        $execution = $this->client->call(Client::METHOD_POST, '/functions/'.$functionId.'/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'data' => 'foobar',
        ]);

        $this->assertEquals(201, $execution['headers']['status-code']);

        $executionId = $execution['body']['$id'] ?? '';

        sleep(10);

        $executions = $this->client->call(Client::METHOD_GET, '/functions/'.$functionId.'/executions/'.$executionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ]);

        $output = json_decode($executions['body']['stdout'], true);
        $this->assertEquals(200, $executions['headers']['status-code']);
        $this->assertEquals('completed', $executions['body']['status']);
        $this->assertEquals($functionId, $output['APPWRITE_FUNCTION_ID']);
        $this->assertEquals('Test', $output['APPWRITE_FUNCTION_NAME']);
        $this->assertEquals($tagId, $output['APPWRITE_FUNCTION_TAG']);
        $this->assertEquals('http', $output['APPWRITE_FUNCTION_TRIGGER']);
        $this->assertEquals('PHP', $output['APPWRITE_FUNCTION_RUNTIME_NAME']);
        $this->assertEquals('8.0', $output['APPWRITE_FUNCTION_RUNTIME_VERSION']);
        $this->assertEquals('', $output['APPWRITE_FUNCTION_EVENT']);
        $this->assertEquals('', $output['APPWRITE_FUNCTION_EVENT_DATA']);
        $this->assertEquals('foobar', $output['APPWRITE_FUNCTION_DATA']);
        $this->assertEquals($this->getUser()['$id'], $output['APPWRITE_FUNCTION_USER_ID']);
        $this->assertNotEmpty($output['APPWRITE_FUNCTION_JWT']);
        $this->assertEquals($projectId, $output['APPWRITE_FUNCTION_PROJECT_ID']);

        return [
            'functionId' => $functionId
        ];
    }

    /**
     * @depends testCreateCustomExecution
     */
    public function testListExecutions(array $data)
    {
        $functionId = $data['functionId'];
        $projectId = $this->getProject()['$id'];
        $apikey = $this->getProject()['apiKey'];

        $execution = $this->client->call(Client::METHOD_POST, '/functions/'.$functionId.'/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'data' => 'foobar',
        ]);

        $this->assertEquals(201, $execution['headers']['status-code']);

        sleep(10);

        $base = $this->client->call(Client::METHOD_GET, '/functions/'.$functionId.'/executions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ]);

        $this->assertEquals(200, $base['headers']['status-code']);
        $this->assertCount(2, $base['body']['executions']);
        $this->assertEquals('completed', $base['body']['executions'][0]['status']);
        $this->assertEquals('completed', $base['body']['executions'][1]['status']);

        $executions = $this->client->call(Client::METHOD_GET, '/functions/'.$functionId.'/executions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ], [
            'cursor' => $base['body']['executions'][0]['$id']
        ]);

        $this->assertCount(1, $executions['body']['executions']);
        $this->assertEquals($base['body']['executions'][1]['$id'], $executions['body']['executions'][0]['$id']);

        $executions = $this->client->call(Client::METHOD_GET, '/functions/'.$functionId.'/executions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ], [
            'cursor' => $base['body']['executions'][1]['$id'],
            'cursorDirection' => Database::CURSOR_BEFORE
        ]);
    }

    public function testSynchronousExecution():array
    {
        /**
         * Test for SUCCESS
         */

        $projectId = $this->getProject()['$id'];
        $apikey = $this->getProject()['apiKey'];

        $function = $this->client->call(Client::METHOD_POST, '/functions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ], [
            'functionId' => 'unique()',
            'name' => 'Test',
            'execute' => ['role:all'],
            'runtime' => 'php-8.0',
            'vars' => [
                'funcKey1' => 'funcValue1',
                'funcKey2' => 'funcValue2',
                'funcKey3' => 'funcValue3',
            ],
            'timeout' => 10,
        ]);

        $functionId = $function['body']['$id'] ?? '';

        $this->assertEquals(201, $function['headers']['status-code']);

        $tag = $this->client->call(Client::METHOD_POST, '/functions/'.$functionId.'/tags', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ], [
            'entrypoint' => 'index.php',
            'code' => new CURLFile(realpath(__DIR__ . '/../../../resources/functions/php-fn.tar.gz'), 'application/x-gzip', 'php-fx.tar.gz'), //different tarball names intentional
        ]);

        $tagId = $tag['body']['$id'] ?? '';

        $this->assertEquals(201, $tag['headers']['status-code']);

        $function = $this->client->call(Client::METHOD_PATCH, '/functions/'.$functionId.'/tag', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ], [
            'tag' => $tagId,
        ]);

        $this->assertEquals(200, $function['headers']['status-code']);

        // Wait for tag to be built.
        sleep(5);

        $execution = $this->client->call(Client::METHOD_POST, '/functions/'.$functionId.'/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'data' => 'foobar',
            'async' => 0
        ]);

        $output = json_decode($execution['body']['response'], true);
        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertEquals('completed', $execution['body']['status']);
        $this->assertEquals($functionId, $output['APPWRITE_FUNCTION_ID']);
        $this->assertEquals('Test', $output['APPWRITE_FUNCTION_NAME']);
        $this->assertEquals($tagId, $output['APPWRITE_FUNCTION_TAG']);
        $this->assertEquals('http', $output['APPWRITE_FUNCTION_TRIGGER']);
        $this->assertEquals('PHP', $output['APPWRITE_FUNCTION_RUNTIME_NAME']);
        $this->assertEquals('8.0', $output['APPWRITE_FUNCTION_RUNTIME_VERSION']);
        $this->assertEquals('', $output['APPWRITE_FUNCTION_EVENT']);
        $this->assertEquals('', $output['APPWRITE_FUNCTION_EVENT_DATA']);
        $this->assertEquals('foobar', $output['APPWRITE_FUNCTION_DATA']);
        $this->assertEquals($this->getUser()['$id'], $output['APPWRITE_FUNCTION_USER_ID']);
        $this->assertNotEmpty($output['APPWRITE_FUNCTION_JWT']);
        $this->assertEquals($projectId, $output['APPWRITE_FUNCTION_PROJECT_ID']);

        return [];
    }
}
