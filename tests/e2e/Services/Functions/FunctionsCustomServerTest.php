<?php

namespace Tests\E2E\Services\Functions;

use CURLFile;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\CLI\Console;

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
            'name' => 'Test',
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

        $functionId = $response1['body']['$id'] ?? '';

        $this->assertEquals(201, $response1['headers']['status-code']);
        $this->assertNotEmpty($response1['body']['$id']);
        $this->assertEquals('Test', $response1['body']['name']);
        $this->assertEquals('php-7.4', $response1['body']['env']);
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
        $this->assertEquals('* * * * *', $response1['body']['schedule']);
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
        $function = $this->client->call(Client::METHOD_GET, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($function['headers']['status-code'], 200);
        $this->assertEquals($function['body']['sum'], 1);
        $this->assertIsArray($function['body']['functions']);
        $this->assertCount(1, $function['body']['functions']);
        $this->assertEquals($function['body']['functions'][0]['name'], 'Test');

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
            'schedule' => '* * * * 1',
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
        $this->assertEquals('* * * * 1', $response1['body']['schedule']);
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
            'command' => 'php function.php',
            'code' => new CURLFile(realpath(__DIR__ . '/../../../resources/functions/php.tar.gz'), 'application/x-gzip', 'php-fx.tar.gz'),
        ]);

        $tagId = $tag['body']['$id'] ?? '';

        $this->assertEquals(201, $tag['headers']['status-code']);
        $this->assertNotEmpty($tag['body']['$id']);
        $this->assertIsInt($tag['body']['dateCreated']);
        $this->assertEquals('php function.php', $tag['body']['command']);
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

        // $execution = $this->client->call(Client::METHOD_GET, '/functions/'.$data['functionId'].'/executions/'.$executionId, array_merge([
        //     'content-type' => 'application/json',
        //     'x-appwrite-project' => $this->getProject()['$id'],
        // ], $this->getHeaders()));

        // $this->assertNotEmpty($execution['body']['$id']);
        // $this->assertNotEmpty($execution['body']['functionId']);
        // $this->assertIsInt($execution['body']['dateCreated']);
        // $this->assertEquals($data['functionId'], $execution['body']['functionId']);
        // $this->assertEquals('completed', $execution['body']['status']);
        // $this->assertEquals(0, $execution['body']['exitCode']);
        // $this->assertStringContainsString('APPWRITE_FUNCTION_ID', $execution['body']['stdout']);
        // $this->assertStringContainsString('APPWRITE_FUNCTION_NAME', $execution['body']['stdout']);
        // $this->assertStringContainsString('APPWRITE_FUNCTION_TAG', $execution['body']['stdout']);
        // $this->assertStringContainsString('APPWRITE_FUNCTION_TRIGGER', $execution['body']['stdout']);
        // $this->assertStringContainsString('APPWRITE_FUNCTION_ENV_NAME', $execution['body']['stdout']);
        // $this->assertStringContainsString('APPWRITE_FUNCTION_ENV_VERSION', $execution['body']['stdout']);
        // $this->assertStringContainsString('Hello World', $execution['body']['stdout']);
        // $this->assertStringContainsString($execution['body']['functionId'], $execution['body']['stdout']);
        // $this->assertEquals('', $execution['body']['stderr']);
        // $this->assertGreaterThan(0.100, $execution['body']['time']);
        // $this->assertLessThan(0.500, $execution['body']['time']);

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

    public function testENVS():array
    {

        $functions = realpath(__DIR__ . '/../../../resources/functions');

        /**
         * Command for rebuilding code packages:
         *  bash tests/resources/functions/package-*.sh
         */
        $envs = [
            [
                'language' => 'PHP',
                'version' => '7.4',
                'name' => 'php-7.4',
                'code' => $functions.'/php.tar.gz',
                'command' => 'php index.php',
                'timeout' => 15,
            ],
            [
                'language' => 'PHP',
                'version' => '8.0',
                'name' => 'php-8.0',
                'code' => $functions.'/php.tar.gz',
                'command' => 'php index.php',
                'timeout' => 15,
            ],
            [
                'language' => 'Python',
                'version' => '3.8',
                'name' => 'python-3.8',
                'code' => $functions.'/python.tar.gz',
                'command' => 'python main.py',
                'timeout' => 15,
            ],
            [
                'language' => 'Node.js',
                'version' => '14.5',
                'name' => 'node-14.5',
                'code' => $functions.'/node.tar.gz',
                'command' => 'node index.js',
                'timeout' => 15,
            ],
            [
                'language' => 'Node.js',
                'version' => '15.5',
                'name' => 'node-15.5',
                'code' => $functions.'/node.tar.gz',
                'command' => 'node index.js',
                'timeout' => 15,
            ],
            [
                'language' => 'Ruby',
                'version' => '2.7',
                'name' => 'ruby-2.7',
                'code' => $functions.'/ruby.tar.gz',
                'command' => 'ruby app.rb',
                'timeout' => 15,
            ],
            [
                'language' => 'Ruby',
                'version' => '3.0',
                'name' => 'ruby-3.0',
                'code' => $functions.'/ruby.tar.gz',
                'command' => 'ruby app.rb',
                'timeout' => 15,
            ],
            [
                'language' => 'Deno',
                'version' => '1.5',
                'name' => 'deno-1.5',
                'code' => $functions.'/deno.tar.gz',
                'command' => 'deno run --allow-env index.ts',
                'timeout' => 15,
            ],
            [
                'language' => 'Deno',
                'version' => '1.6',
                'name' => 'deno-1.6',
                'code' => $functions.'/deno.tar.gz',
                'command' => 'deno run --allow-env index.ts',
                'timeout' => 15,
            ],
            [
                'language' => 'Dart',
                'version' => '2.10',
                'name' => 'dart-2.10',
                'code' => $functions.'/dart.tar.gz',
                'command' => 'dart main.dart',
                'timeout' => 15,
            ],
            [
                'language' => '.NET',
                'version' => '3.1',
                'name' => 'dotnet-3.1',
                'code' => $functions.'/dotnet-3.1.tar.gz',
                'command' => 'dotnet dotnet.dll',
                'timeout' => 15,
            ],
            [
                'language' => '.NET',
                'version' => '5.0',
                'name' => 'dotnet-5.0',
                'code' => $functions.'/dotnet-5.0.tar.gz',
                'command' => 'dotnet dotnet.dll',
                'timeout' => 15,
            ],
        ];

        sleep(count($envs) * 20);
        fwrite(STDERR, ".");

        /**
         * Test for SUCCESS
         */
        $file = $this->client->call(Client::METHOD_POST, '/storage/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
            'read' => ['*'],
            'write' => ['*'],
            'folderId' => 'xyz',
        ]);

        $this->assertEquals($file['headers']['status-code'], 201);
        $this->assertNotEmpty($file['body']['$id']);

        $fileId = $file['body']['$id'] ?? '';

        foreach ($envs as $key => $env) {
            $language = $env['language'] ?? '';
            $version = $env['version'] ?? '';
            $name = $env['name'] ?? '';
            $code = $env['code'] ?? '';
            $command = $env['command'] ?? '';
            $timeout = $env['timeout'] ?? 15;

            $function = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'name' => 'Test '.$name,
                'env' => $name,
                'vars' => [
                    'APPWRITE_ENDPOINT' => 'http://appwrite.test/v1',
                    'APPWRITE_PROJECT' => $this->getProject()['$id'],
                    'APPWRITE_SECRET' => $this->getProject()['apiKey'],
                    'APPWRITE_FILEID' => $fileId,
                ],
                'events' => [],
                'schedule' => '',
                'timeout' => $timeout,
            ]);

            // var_dump('http://'.gethostbyname(trim(`hostname`)).'/v1');
    
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

            if($executions['body']['executions'][0]['status'] !== 'completed') {
                var_dump($env);
                var_dump($executions['body']['executions'][0]);
                $stdout = '';
                $stderr = '';
                Console::execute('docker logs appwrite-worker-functions', '', $stdout, $stderr);
                var_dump($stdout);
                var_dump($stderr);
            }
    
            $this->assertEquals($executions['headers']['status-code'], 200);
            $this->assertEquals($executions['body']['sum'], 1);
            $this->assertIsArray($executions['body']['executions']);
            $this->assertCount(1, $executions['body']['executions']);
            $this->assertEquals($executions['body']['executions'][0]['$id'], $executionId);
            $this->assertEquals($executions['body']['executions'][0]['trigger'], 'http');
            $this->assertEquals($executions['body']['executions'][0]['status'], 'completed');
            $this->assertEquals($executions['body']['executions'][0]['exitCode'], 0);
            
            $stdout = explode("\n", $executions['body']['executions'][0]['stdout']);
            
            $this->assertEquals($stdout[0], $functionId);
            $this->assertEquals($stdout[1], 'Test '.$name);
            $this->assertEquals($stdout[2], $tagId);
            $this->assertEquals($stdout[3], 'http');
            $this->assertEquals($stdout[4], $language);
            $this->assertEquals($stdout[5], $version);
            // $this->assertEquals($stdout[6], $fileId);
            fwrite(STDERR, ".");
        }

        return [
            'functionId' => $functionId,
        ];
    }
    /**
     * @depends testENVS
     */
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
            'name' => 'Test '.$name,
            'env' => $name,
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
        $this->assertEquals($executions['body']['executions'][0]['exitCode'], 1);
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
            'name' => 'Test '.$name,
            'env' => $name,
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
        $this->assertStringContainsString('foobar', $executions['body']['executions'][0]['stdout']);
    }
}
