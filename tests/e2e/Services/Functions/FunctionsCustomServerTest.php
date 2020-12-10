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

    public function testENVS():array
    {
        sleep(60);

        $functions = realpath(__DIR__ . '/../../../resources/functions');

        /**
         * Command for rebuilding code packages:
         *  bash tests/resources/functions/package.sh
         */

        $envs = [
            //[
            //    'name' => 'php-7.4',
            //    'code' => $functions.'/php-fx.tar.gz',
            //    'command' => 'php function.php',
            //],

            [
                'language' => 'Python',
                'version' => '3.8',
                'name' => 'python-3.8',
                'code' => $functions.'/python.tar.gz',
                'command' => 'python main.py',
            ],
        ];

        foreach ($envs as $key => $env) {
            $language = $env['language'] ?? '';
            $version = $env['version'] ?? '';
            $name = $env['name'] ?? '';
            $code = $env['code'] ?? '';
            $command = $env['command'] ?? '';

            $function = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'name' => 'Test '.$name,
                'env' => $name,
                'vars' => [
                    'APPWRITE_ENDPOINT' => 'http://'.gethostbyname(trim(`hostname`)).'/v1',
                    'APPWRITE_PROJECT' => $this->getProject()['$id'],
                    'APPWRITE_SECRET' => $this->getProject()['apiKey'],
                ],
                'events' => [],
                'schedule' => '',
                'timeout' => 10,
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

            sleep(15);

            $executions = $this->client->call(Client::METHOD_GET, '/functions/'.$functionId.'/executions', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            var_dump($executions['body']['executions'][0]);
            var_dump($executions['body']['executions'][0]['stdout']);
            var_dump($executions['body']['executions'][0]['stderr']);
    
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
        }

        return [
            'functionId' => $functionId,
        ];
    }
}