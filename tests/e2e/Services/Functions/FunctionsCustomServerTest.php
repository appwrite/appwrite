<?php

namespace Tests\E2E\Services\Functions;

use CURLFile;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

class FunctionsCustomServerTest extends Scope
{
    use FunctionsBase;
    use ProjectCustom;
    use SideServer;

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

        $functionId = $response1['body']['$id'] ?? '';

        $this->assertEquals(201, $response1['headers']['status-code']);
        $this->assertNotEmpty($response1['body']['$id']);
        $this->assertEquals('Test', $response1['body']['name']);
        $this->assertEquals('php-8.0', $response1['body']['runtime']);
        $this->assertIsInt($response1['body']['dateCreated']);
        $this->assertIsInt($response1['body']['dateUpdated']);
        $this->assertEquals('', $response1['body']['tag']);
        $this->assertEquals([
            'funcKey1' => 'funcValue1',
            'funcKey2' => 'funcValue2',
            'funcKey3' => 'funcValue3',
        ], $response1['body']['vars']);
        $this->assertEquals([
            'account.create',
            'account.delete',
        ], $response1['body']['events']);
        $this->assertEquals('0 0 1 1 *', $response1['body']['schedule']);
        $this->assertEquals(10, $response1['body']['timeout']);
       
        /**
         * Test for FAILURE
         */

        return [
            'functionId' => $functionId,
        ];
    }

    /**
     * @depends testCreate
     */
    public function testList(array $data):array
    {
        /**
         * Test for SUCCESS
         */

        /**
         * Test search queries
         */
        $response = $this->client->call(Client::METHOD_GET, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => $data['functionId']
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertCount(1, $response['body']['functions']);
        $this->assertEquals($response['body']['functions'][0]['name'], 'Test');

        $response = $this->client->call(Client::METHOD_GET, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'Test'
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertCount(1, $response['body']['functions']);
        $this->assertEquals($response['body']['functions'][0]['$id'], $data['functionId']);

        $response = $this->client->call(Client::METHOD_GET, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'php-8.0'
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertCount(1, $response['body']['functions']);
        $this->assertEquals($response['body']['functions'][0]['$id'], $data['functionId']);

        /**
         * Test pagination
         */
        $response = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'functionId' => 'unique()',
            'name' => 'Test 2',
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
        $this->assertNotEmpty($response['body']['$id']);

        $functions = $this->client->call(Client::METHOD_GET, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($functions['headers']['status-code'], 200);
        $this->assertEquals($functions['body']['sum'], 2);
        $this->assertIsArray($functions['body']['functions']);
        $this->assertCount(2, $functions['body']['functions']);
        $this->assertEquals($functions['body']['functions'][0]['name'], 'Test');
        $this->assertEquals($functions['body']['functions'][1]['name'], 'Test 2');

        $response = $this->client->call(Client::METHOD_GET, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'after' => $functions['body']['functions'][0]['$id']
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertCount(1, $response['body']['functions']);
        $this->assertEquals($response['body']['functions'][0]['name'], 'Test 2');

        return $data;
    }

    /**
     * @depends testList
     */
    public function testGet(array $data):array
    {
        /**
         * Test for SUCCESS
         */
        $function = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($function['headers']['status-code'], 200);
        $this->assertEquals($function['body']['name'], 'Test');
               
        /**
         * Test for FAILURE
         */
        $function = $this->client->call(Client::METHOD_GET, '/functions/x', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($function['headers']['status-code'], 404);

        return $data;
    }

    /**
     * @depends testGet
     */
    public function testUpdate($data):array
    {
        /**
         * Test for SUCCESS
         */
        $response1 = $this->client->call(Client::METHOD_PUT, '/functions/'.$data['functionId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Test1',
            'vars' => [
                'key4' => 'value4',
                'key5' => 'value5',
                'key6' => 'value6',
            ],
            'events' => [
                'account.update.name',
                'account.update.email',
            ],
            'schedule' => '0 0 1 1 *',
            'timeout' => 5,
        ]);

        $this->assertEquals(200, $response1['headers']['status-code']);
        $this->assertNotEmpty($response1['body']['$id']);
        $this->assertEquals('Test1', $response1['body']['name']);
        $this->assertIsInt($response1['body']['dateCreated']);
        $this->assertIsInt($response1['body']['dateUpdated']);
        $this->assertEquals('', $response1['body']['tag']);
        $this->assertEquals([
            'key4' => 'value4',
            'key5' => 'value5',
            'key6' => 'value6',
        ], $response1['body']['vars']);
        $this->assertEquals([
            'account.update.name',
            'account.update.email',
        ], $response1['body']['events']);
        $this->assertEquals('0 0 1 1 *', $response1['body']['schedule']);
        $this->assertEquals(5, $response1['body']['timeout']);
       
        /**
         * Test for FAILURE
         */

        return $data;
    }

    /**
     * @depends testUpdate
     */
    public function testCreateTag($data):array
    {
        /**
         * Test for SUCCESS
         */
        $tag = $this->client->call(Client::METHOD_POST, '/functions/'.$data['functionId'].'/tags', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'command' => 'php index.php',
            'code' => new CURLFile(realpath(__DIR__ . '/../../../resources/functions/php.tar.gz'), 'application/x-gzip', 'php-fx.tar.gz'),
        ]);

        $tagId = $tag['body']['$id'] ?? '';

        $this->assertEquals(201, $tag['headers']['status-code']);
        $this->assertNotEmpty($tag['body']['$id']);
        $this->assertIsInt($tag['body']['dateCreated']);
        $this->assertEquals('php index.php', $tag['body']['command']);
        $this->assertGreaterThan(10000, $tag['body']['size']);
       
        /**
         * Test for FAILURE
         */

        return array_merge($data, ['tagId' => $tagId]);
    }

    /**
     * @depends testCreateTag
     */
    public function testUpdateTag($data):array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/functions/'.$data['functionId'].'/tag', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'tag' => $data['tagId'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertIsInt($response['body']['dateCreated']);
        $this->assertIsInt($response['body']['dateUpdated']);
        $this->assertEquals($data['tagId'], $response['body']['tag']);
       
        /**
         * Test for FAILURE
         */

        return $data;
    }

    /**
     * @depends testCreateTag
     */
    public function testListTags(array $data):array
    {
        /**
         * Test for SUCCESS
         */
        $function = $this->client->call(Client::METHOD_GET, '/functions/'.$data['functionId'].'/tags', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($function['headers']['status-code'], 200);
        $this->assertEquals($function['body']['sum'], 1);
        $this->assertIsArray($function['body']['tags']);
        $this->assertCount(1, $function['body']['tags']);

        /**
         * Test search queries
         */
        $function = $this->client->call(Client::METHOD_GET, '/functions/'.$data['functionId'].'/tags', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders(), [
            'search' => $data['functionId']
        ]));

        $this->assertEquals($function['headers']['status-code'], 200);
        $this->assertEquals($function['body']['sum'], 1);
        $this->assertIsArray($function['body']['tags']);
        $this->assertCount(1, $function['body']['tags']);
        $this->assertEquals($function['body']['tags'][0]['$id'], $data['tagId']);

        $function = $this->client->call(Client::METHOD_GET, '/functions/'.$data['functionId'].'/tags', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders(), [
            'search' => 'Test'
        ]));

        $this->assertEquals($function['headers']['status-code'], 200);
        $this->assertEquals($function['body']['sum'], 1);
        $this->assertIsArray($function['body']['tags']);
        $this->assertCount(1, $function['body']['tags']);
        $this->assertEquals($function['body']['tags'][0]['$id'], $data['tagId']);

        $function = $this->client->call(Client::METHOD_GET, '/functions/'.$data['functionId'].'/tags', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders(), [
            'search' => 'php-8.0'
        ]));

        $this->assertEquals($function['headers']['status-code'], 200);
        $this->assertEquals($function['body']['sum'], 1);
        $this->assertIsArray($function['body']['tags']);
        $this->assertCount(1, $function['body']['tags']);
        $this->assertEquals($function['body']['tags'][0]['$id'], $data['tagId']);

        return $data;
    }

    /**
     * @depends testCreateTag
     */
    public function testGetTag(array $data):array
    {
        /**
         * Test for SUCCESS
         */
        $function = $this->client->call(Client::METHOD_GET, '/functions/'.$data['functionId'].'/tags/' . $data['tagId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $function['headers']['status-code']);
        $this->assertGreaterThan(10000, $function['body']['size']);

        /**
         * Test for FAILURE
         */
        $function = $this->client->call(Client::METHOD_GET, '/functions/'.$data['functionId'].'/tags/x', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($function['headers']['status-code'], 404);

        return $data;
    }

    /**
     * @depends testUpdateTag
     */
    public function testCreateExecution($data):array
    {
        /**
         * Test for SUCCESS
         */
        $execution = $this->client->call(Client::METHOD_POST, '/functions/'.$data['functionId'].'/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'async' => 1,
        ]);

        $executionId = $execution['body']['$id'] ?? '';

        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertNotEmpty($execution['body']['$id']);
        $this->assertNotEmpty($execution['body']['functionId']);
        $this->assertIsInt($execution['body']['dateCreated']);
        $this->assertEquals($data['functionId'], $execution['body']['functionId']);
        $this->assertEquals('waiting', $execution['body']['status']);
        $this->assertEquals(0, $execution['body']['exitCode']);
        $this->assertEquals('', $execution['body']['stdout']);
        $this->assertEquals('', $execution['body']['stderr']);
        $this->assertEquals(0, $execution['body']['time']);

        sleep(10);

        $execution = $this->client->call(Client::METHOD_GET, '/functions/'.$data['functionId'].'/executions/'.$executionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertNotEmpty($execution['body']['$id']);
        $this->assertNotEmpty($execution['body']['functionId']);
        $this->assertIsInt($execution['body']['dateCreated']);
        $this->assertEquals($data['functionId'], $execution['body']['functionId']);
        $this->assertEquals('completed', $execution['body']['status']);
        $this->assertEquals(0, $execution['body']['exitCode']);
        $this->assertStringContainsString($execution['body']['functionId'], $execution['body']['stdout']);
        $this->assertStringContainsString($data['tagId'], $execution['body']['stdout']);
        $this->assertStringContainsString('Test1', $execution['body']['stdout']);
        $this->assertStringContainsString('http', $execution['body']['stdout']);
        $this->assertStringContainsString('PHP', $execution['body']['stdout']);
        $this->assertStringContainsString('8.0', $execution['body']['stdout']);
        $this->assertStringContainsString('êä', $execution['body']['stdout']); // tests unknown utf-8 chars
        $this->assertEquals('', $execution['body']['stderr']);
        $this->assertGreaterThan(0.05, $execution['body']['time']);
        $this->assertLessThan(0.500, $execution['body']['time']);

        /**
         * Test for FAILURE
         */

        sleep(10);

        return array_merge($data, ['executionId' => $executionId]);
    }

    /**
     * @depends testCreateExecution
     */
    public function testListExecutions(array $data):array
    {
        /**
         * Test for SUCCESS
         */
        $function = $this->client->call(Client::METHOD_GET, '/functions/'.$data['functionId'].'/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($function['headers']['status-code'], 200);
        $this->assertEquals($function['body']['sum'], 1);
        $this->assertIsArray($function['body']['executions']);
        $this->assertCount(1, $function['body']['executions']);
        $this->assertEquals($function['body']['executions'][0]['$id'], $data['executionId']);

        /**
         * Test search queries
         */

        $response = $this->client->call(Client::METHOD_GET, '/functions/'.$data['functionId'].'/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => $data['executionId'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['sum']);
        $this->assertIsInt($response['body']['sum']);
        $this->assertCount(1, $response['body']['executions']);
        $this->assertEquals($data['functionId'], $response['body']['executions'][0]['functionId']);

        $response = $this->client->call(Client::METHOD_GET, '/functions/'.$data['functionId'].'/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => $data['functionId'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['sum']);
        $this->assertIsInt($response['body']['sum']);
        $this->assertCount(1, $response['body']['executions']);
        $this->assertEquals($data['executionId'], $response['body']['executions'][0]['$id']);

        return $data;
    }

    /**
     * @depends testListExecutions
     */
    public function testGetExecution(array $data):array
    {
        /**
         * Test for SUCCESS
         */
        $function = $this->client->call(Client::METHOD_GET, '/functions/'.$data['functionId'].'/executions/' . $data['executionId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($function['headers']['status-code'], 200);
        $this->assertEquals($function['body']['$id'], $data['executionId']);

        /**
         * Test for FAILURE
         */
        $function = $this->client->call(Client::METHOD_GET, '/functions/'.$data['functionId'].'/executions/x', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($function['headers']['status-code'], 404);

        return $data;
    }

    /**
     * @depends testGetExecution
     */
    public function testDeleteTag($data):array
    {
        /**
         * Test for SUCCESS
         */
        $function = $this->client->call(Client::METHOD_DELETE, '/functions/'.$data['functionId'].'/tags/' . $data['tagId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $function['headers']['status-code']);
        $this->assertEmpty($function['body']);

        $function = $this->client->call(Client::METHOD_GET, '/functions/'.$data['functionId'].'/tags/' . $data['tagId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
       
        $this->assertEquals(404, $function['headers']['status-code']);

        /**
         * Test for FAILURE
         */

        return $data;
    }

    /**
     * @depends testCreateTag
     */
    public function testDelete($data):array
    {
        /**
         * Test for SUCCESS
         */
        $function = $this->client->call(Client::METHOD_DELETE, '/functions/'.$data['functionId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $function['headers']['status-code']);
        $this->assertEmpty($function['body']);

        $function = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
       
        $this->assertEquals(404, $function['headers']['status-code']);

        /**
         * Test for FAILURE
         */

        return $data;
    }

    public function testTimeout()
    {
        $name = 'php-8.0';
        $code = realpath(__DIR__ . '/../../../resources/functions').'/timeout.tar.gz';
        $command = 'php index.php';
        $timeout = 2;

        $function = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'functionId' => 'unique()',
            'name' => 'Test '.$name,
            'runtime' => $name,
            'vars' => [],
            'events' => [],
            'schedule' => '',
            'timeout' => $timeout,
        ]);

        $functionId = $function['body']['$id'] ?? '';

        $this->assertEquals(201, $function['headers']['status-code']);

        $tag = $this->client->call(Client::METHOD_POST, '/functions/'.$functionId.'/tags', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'command' => $command,
            'code' => new CURLFile($code, 'application/x-gzip', basename($code)),
        ]);

        $tagId = $tag['body']['$id'] ?? '';
        $this->assertEquals(201, $tag['headers']['status-code']);

        $tag = $this->client->call(Client::METHOD_PATCH, '/functions/'.$functionId.'/tag', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'tag' => $tagId,
        ]);

        $this->assertEquals(200, $tag['headers']['status-code']);
       
        $execution = $this->client->call(Client::METHOD_POST, '/functions/'.$functionId.'/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'async' => 1,
        ]);

        $executionId = $execution['body']['$id'] ?? '';
        
        $this->assertEquals(201, $execution['headers']['status-code']);

        sleep(10);

        $executions = $this->client->call(Client::METHOD_GET, '/functions/'.$functionId.'/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($executions['headers']['status-code'], 200);
        $this->assertEquals($executions['body']['sum'], 1);
        $this->assertIsArray($executions['body']['executions']);
        $this->assertCount(1, $executions['body']['executions']);
        $this->assertEquals($executions['body']['executions'][0]['$id'], $executionId);
        $this->assertEquals($executions['body']['executions'][0]['trigger'], 'http');
        $this->assertEquals($executions['body']['executions'][0]['status'], 'failed');
        $this->assertEquals($executions['body']['executions'][0]['exitCode'], 124);
        $this->assertGreaterThan(2, $executions['body']['executions'][0]['time']);
        $this->assertLessThan(3, $executions['body']['executions'][0]['time']);
        $this->assertEquals($executions['body']['executions'][0]['stdout'], '');
        $this->assertEquals($executions['body']['executions'][0]['stderr'], '');
    }

    /**
     * @depends testTimeout
     */
    public function testCreateCustomExecution()
    {
        $name = 'php-8.0';
        $code = realpath(__DIR__ . '/../../../resources/functions').'/php-fn.tar.gz';
        $command = 'php index.php';
        $timeout = 2;

        $function = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'functionId' => 'unique()',
            'name' => 'Test '.$name,
            'runtime' => $name,
            'vars' => [],
            'events' => [],
            'schedule' => '',
            'timeout' => $timeout,
        ]);

        $functionId = $function['body']['$id'] ?? '';

        $this->assertEquals(201, $function['headers']['status-code']);

        $tag = $this->client->call(Client::METHOD_POST, '/functions/'.$functionId.'/tags', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'command' => $command,
            'code' => new CURLFile($code, 'application/x-gzip', basename($code)),
        ]);

        $tagId = $tag['body']['$id'] ?? '';
        $this->assertEquals(201, $tag['headers']['status-code']);

        $tag = $this->client->call(Client::METHOD_PATCH, '/functions/'.$functionId.'/tag', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'tag' => $tagId,
        ]);

        $this->assertEquals(200, $tag['headers']['status-code']);
       
        $execution = $this->client->call(Client::METHOD_POST, '/functions/'.$functionId.'/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => 'foobar',
        ]);

        $executionId = $execution['body']['$id'] ?? '';
        
        $this->assertEquals(201, $execution['headers']['status-code']);

        $executionId = $execution['body']['$id'] ?? '';
        
        sleep(10);

        $executions = $this->client->call(Client::METHOD_GET, '/functions/'.$functionId.'/executions/'.$executionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $output = json_decode($executions['body']['stdout'], true);

        $this->assertEquals(200, $executions['headers']['status-code']);
        $this->assertEquals('completed', $executions['body']['status']);
        $this->assertEquals($functionId, $output['APPWRITE_FUNCTION_ID']);
        $this->assertEquals('Test '.$name, $output['APPWRITE_FUNCTION_NAME']);
        $this->assertEquals($tagId, $output['APPWRITE_FUNCTION_TAG']);
        $this->assertEquals('http', $output['APPWRITE_FUNCTION_TRIGGER']);
        $this->assertEquals('PHP', $output['APPWRITE_FUNCTION_RUNTIME_NAME']);
        $this->assertEquals('8.0', $output['APPWRITE_FUNCTION_RUNTIME_VERSION']);
        $this->assertEquals('', $output['APPWRITE_FUNCTION_EVENT']);
        $this->assertEquals('', $output['APPWRITE_FUNCTION_EVENT_DATA']);
        $this->assertEquals('foobar', $output['APPWRITE_FUNCTION_DATA']);
        $this->assertEquals('', $output['APPWRITE_FUNCTION_USER_ID']);
        $this->assertEmpty($output['APPWRITE_FUNCTION_JWT']);
        $this->assertEquals($this->getProject()['$id'], $output['APPWRITE_FUNCTION_PROJECT_ID']);

        $executions = $this->client->call(Client::METHOD_GET, '/functions/'.$functionId.'/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        
        $this->assertEquals($executions['headers']['status-code'], 200);
        $this->assertEquals($executions['body']['sum'], 1);
        $this->assertIsArray($executions['body']['executions']);
        $this->assertCount(1, $executions['body']['executions']);
        $this->assertEquals($executions['body']['executions'][0]['$id'], $executionId);
        $this->assertEquals($executions['body']['executions'][0]['trigger'], 'http');
        $this->assertStringContainsString('foobar', $executions['body']['executions'][0]['stdout']);
    }
}
