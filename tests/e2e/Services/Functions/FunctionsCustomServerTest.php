<?php

namespace Tests\E2E\Services\Functions;

use Appwrite\Functions\Specification;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\CLI\Console;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\System\System;

class FunctionsCustomServerTest extends Scope
{
    use FunctionsBase;
    use ProjectCustom;
    use SideServer;

    public function testCreateFunction(): array
    {
        /**
         * Test for SUCCESS
         */
        $function = $this->createFunction([
            'functionId' => ID::unique(),
            'name' => 'Test',
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'events' => [
                'buckets.*.create',
                'buckets.*.delete',
            ],
            'timeout' => 10,
        ]);

        $functionId = $functionId = $function['body']['$id'] ?? '';

        $dateValidator = new DatetimeValidator();
        $this->assertEquals(201, $function['headers']['status-code']);
        $this->assertNotEmpty($function['body']['$id']);
        $this->assertEquals('Test', $function['body']['name']);
        $this->assertEquals('php-8.0', $function['body']['runtime']);
        $this->assertEquals(true, $dateValidator->isValid($function['body']['$createdAt']));
        $this->assertEquals(true, $dateValidator->isValid($function['body']['$updatedAt']));
        $this->assertEquals('', $function['body']['deployment']);
        $this->assertEquals([
            'buckets.*.create',
            'buckets.*.delete',
        ], $function['body']['events']);
        $this->assertEmpty($function['body']['schedule']);
        $this->assertEquals(10, $function['body']['timeout']);

        $variable = $this->createVariable($functionId, [
            'key' => 'funcKey1',
            'value' => 'funcValue1',
        ]);
        $variable2 = $this->createVariable($functionId, [
            'key' => 'funcKey2',
            'value' => 'funcValue2',
        ]);
        $variable3 = $this->createVariable($functionId, [
            'key' => 'funcKey3',
            'value' => 'funcValue3',
        ]);

        $this->assertEquals(201, $variable['headers']['status-code']);
        $this->assertEquals(201, $variable2['headers']['status-code']);
        $this->assertEquals(201, $variable3['headers']['status-code']);

        return [
            'functionId' => $functionId,
        ];
    }

    /**
     * @depends testCreateFunction
     */
    public function testListFunctions(array $data): array
    {
        /**
         * Test for SUCCESS
         */
        // Test search id
        $functions = $this->listFunctions([
            'search' => $data['functionId']
        ]);

        $this->assertEquals($functions['headers']['status-code'], 200);
        $this->assertCount(1, $functions['body']['functions']);
        $this->assertEquals($functions['body']['functions'][0]['name'], 'Test');

        // Test pagination limit
        $functions = $this->listFunctions([
            'queries' => [
                Query::limit(1)->toString(),
            ],
        ]);

        $this->assertEquals($functions['headers']['status-code'], 200);
        $this->assertCount(1, $functions['body']['functions']);

        // Test pagination offset
        $functions = $this->listFunctions([
            'queries' => [
                Query::offset(1)->toString(),
            ],
        ]);

        $this->assertEquals($functions['headers']['status-code'], 200);
        $this->assertCount(0, $functions['body']['functions']);

        // Test filter enabled
        $functions = $this->listFunctions([
            'queries' => [
                Query::equal('enabled', [true])->toString(),
            ],
        ]);

        $this->assertEquals($functions['headers']['status-code'], 200);
        $this->assertCount(1, $functions['body']['functions']);

        // Test filter disabled
        $functions = $this->listFunctions([
            'queries' => [
                Query::equal('enabled', [false])->toString(),
            ],
        ]);

        $this->assertEquals($functions['headers']['status-code'], 200);
        $this->assertCount(0, $functions['body']['functions']);

        // Test search name
        $functions = $this->listFunctions([
            'search' => 'Test'
        ]);

        $this->assertEquals($functions['headers']['status-code'], 200);
        $this->assertCount(1, $functions['body']['functions']);
        $this->assertEquals($functions['body']['functions'][0]['$id'], $data['functionId']);

        // Test search runtime
        $functions = $this->listFunctions([
            'search' => 'php-8.0'
        ]);

        $this->assertEquals($functions['headers']['status-code'], 200);
        $this->assertCount(1, $functions['body']['functions']);
        $this->assertEquals($functions['body']['functions'][0]['$id'], $data['functionId']);

        /**
         * Test pagination
         */
        $this->setupFunction([
            'functionId' => ID::unique(),
            'name' => 'Test 2',
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'events' => [
                'buckets.*.create',
                'buckets.*.delete',
            ],
            'timeout' => 10,
        ]);

        $functions = $this->listFunctions();

        $this->assertEquals($functions['headers']['status-code'], 200);
        $this->assertEquals($functions['body']['total'], 2);
        $this->assertIsArray($functions['body']['functions']);
        $this->assertCount(2, $functions['body']['functions']);
        $this->assertEquals($functions['body']['functions'][0]['name'], 'Test');
        $this->assertEquals($functions['body']['functions'][1]['name'], 'Test 2');

        $functions1 = $this->listFunctions([
            'queries' => [
                Query::cursorAfter(new Document(['$id' => $functions['body']['functions'][0]['$id']]))->toString(),
            ],
        ]);

        $this->assertEquals($functions1['headers']['status-code'], 200);
        $this->assertCount(1, $functions1['body']['functions']);
        $this->assertEquals($functions1['body']['functions'][0]['name'], 'Test 2');

        $functions2 = $this->listFunctions([
            'queries' => [
                Query::cursorBefore(new Document(['$id' => $functions['body']['functions'][1]['$id']]))->toString(),
            ],
        ]);

        $this->assertEquals($functions2['headers']['status-code'], 200);
        $this->assertCount(1, $functions2['body']['functions']);
        $this->assertEquals($functions2['body']['functions'][0]['name'], 'Test');

        /**
         * Test for FAILURE
         */
        $functions = $this->listFunctions([
            'queries' => [
                Query::cursorAfter(new Document(['$id' => 'unknown']))->toString(),
            ],
        ]);
        $this->assertEquals($functions['headers']['status-code'], 400);

        return $data;
    }

    /**
     * @depends testListFunctions
     */
    public function testGetFunction(array $data): array
    {
        /**
         * Test for SUCCESS
         */
        $function = $this->getFunction($data['functionId']);

        $this->assertEquals($function['headers']['status-code'], 200);
        $this->assertEquals($function['body']['name'], 'Test');

        /**
         * Test for FAILURE
         */
        $function = $this->getFunction('x');

        $this->assertEquals($function['headers']['status-code'], 404);

        return $data;
    }

    /**
     * @depends testGetFunction
     */
    public function testUpdateFunction($data): array
    {
        /**
         * Test for SUCCESS
         */
        $function = $this->client->call(Client::METHOD_PUT, '/functions/' . $data['functionId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Test1',
            'events' => [
                'users.*.update.name',
                'users.*.update.email',
            ],
            'schedule' => '0 0 1 1 *',
            'timeout' => 15,
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
        ]);

        $dateValidator = new DatetimeValidator();

        $this->assertEquals(200, $function['headers']['status-code']);
        $this->assertNotEmpty($function['body']['$id']);
        $this->assertEquals('Test1', $function['body']['name']);
        $this->assertEquals(true, $dateValidator->isValid($function['body']['$createdAt']));
        $this->assertEquals(true, $dateValidator->isValid($function['body']['$updatedAt']));
        $this->assertEquals('', $function['body']['deployment']);
        $this->assertEquals([
            'users.*.update.name',
            'users.*.update.email',
        ], $function['body']['events']);
        $this->assertEquals('0 0 1 1 *', $function['body']['schedule']);
        $this->assertEquals(15, $function['body']['timeout']);

        // Create a variable for later tests
        $variable = $this->createVariable($data['functionId'], [
            'key' => 'GLOBAL_VARIABLE',
            'value' => 'Global Variable Value',
        ]);

        $this->assertEquals(201, $variable['headers']['status-code']);

        return $data;
    }

    public function testCreateDeploymentFromCLI()
    {
        $functionId = $this->setupFunction([
            'functionId' => ID::unique(),
            'name' => 'Test',
            'execute' => [Role::user($this->getUser()['$id'])->toString()],
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'events' => [
                'users.*.create',
                'users.*.delete',
            ],
            'schedule' => '0 0 1 1 *', // Once a year
            'timeout' => 10,
        ]);

        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/deployments', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
            'x-sdk-language' => 'cli',
        ], [
            'entrypoint' => 'index.php',
            'code' => $this->packageFunction('php'),
            'activate' => true
        ]);

        $this->assertEquals(202, $deployment['headers']['status-code']);

        $deploymentId = $deployment['body']['$id'] ?? '';

        $this->assertEventually(function () use ($functionId, $deploymentId) {
            $deployment = $this->getDeployment($functionId, $deploymentId);

            $this->assertEquals(200, $deployment['headers']['status-code']);
            $this->assertEquals('ready', $deployment['body']['status']);
            $this->assertEquals('cli', $deployment['body']['type']);
        }, 500000, 1000);
    }

    public function testCreateFunctionAndDeploymentFromTemplate()
    {

        $starterTemplate = $this->getTemplate('starter');
        $this->assertEquals(200, $starterTemplate['headers']['status-code']);

        $phpRuntime = array_values(array_filter($starterTemplate['body']['runtimes'], function ($runtime) {
            return $runtime['name'] === 'php-8.0';
        }))[0];

        // If this fails, the template has variables, and this test needs to be updated
        $this->assertEmpty($starterTemplate['body']['variables']);

        $function = $this->createFunction(
            [
                'functionId' => ID::unique(),
                'name' => $starterTemplate['body']['name'],
                'runtime' => 'php-8.0',
                'execute' => $starterTemplate['body']['permissions'],
                'entrypoint' => $phpRuntime['entrypoint'],
                'events' => $starterTemplate['body']['events'],
                'schedule' => $starterTemplate['body']['cron'],
                'timeout' => $starterTemplate['body']['timeout'],
                'commands' => $phpRuntime['commands'],
                'scopes' => $starterTemplate['body']['scopes'],
                'templateRepository' => $starterTemplate['body']['providerRepositoryId'],
                'templateOwner' => $starterTemplate['body']['providerOwner'],
                'templateRootDirectory' => $phpRuntime['providerRootDirectory'],
                'templateVersion' => $starterTemplate['body']['providerVersion'],
            ]
        );

        $this->assertEquals(201, $function['headers']['status-code']);
        $this->assertNotEmpty($function['body']['$id']);

        $functionId = $functionId = $function['body']['$id'] ?? '';

        $deployments = $this->listDeployments($functionId);

        $this->assertEquals(200, $deployments['headers']['status-code']);
        $this->assertEquals(1, $deployments['body']['total']);

        $lastDeployment = $deployments['body']['deployments'][0];

        $this->assertNotEmpty($lastDeployment['$id']);
        $this->assertEquals(0, $lastDeployment['size']);

        $deploymentId = $lastDeployment['$id'];

        $this->assertEventually(function () use ($functionId, $deploymentId) {
            $deployment = $this->getDeployment($functionId, $deploymentId);

            $this->assertEquals(200, $deployment['headers']['status-code']);
            $this->assertEquals('ready', $deployment['body']['status']);
        }, 500000, 1000);

        $function = $this->getFunction($functionId);

        $this->assertEquals(200, $function['headers']['status-code']);
        $this->assertEquals($deploymentId, $function['body']['deployment']);

        // Test starter code is used and that dynamic keys work
        $execution = $this->createExecution($functionId, [
            'path' => '/ping',
        ]);

        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertEquals("completed", $execution['body']['status']);
        $this->assertEquals(200, $execution['body']['responseStatusCode']);
        $this->assertEquals("Pong", $execution['body']['responseBody']);
        $this->assertEmpty($execution['body']['errors']);

        // Test execution logged correct total users
        $users = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $users['headers']['status-code']);
        $this->assertIsInt($users['body']['total']);

        $totalUsers = $users['body']['total'];

        $this->assertStringContainsString("Total users: " . $totalUsers, $execution['body']['logs']);

        // Execute function again but async
        $execution = $this->createExecution($functionId, [
            'path' => '/ping',
            'async' => true
        ]);

        $this->assertEquals(202, $execution['headers']['status-code']);
        $this->assertNotEmpty($execution['body']['$id']);
        $this->assertEquals('waiting', $execution['body']['status']);

        $executionId = $execution['body']['$id'] ?? '';

        $this->assertEventually(function () use ($functionId, $executionId, $totalUsers) {
            $execution = $this->getExecution($functionId, $executionId);

            $this->assertEquals(200, $execution['headers']['status-code']);
            $this->assertEquals(200, $execution['body']['responseStatusCode']);
            $this->assertEquals('completed', $execution['body']['status']);
            $this->assertEmpty($execution['body']['responseBody']);
            $this->assertEmpty($execution['body']['errors']);
            $this->assertStringContainsString("Total users: " . $totalUsers, $execution['body']['logs']);
        }, 10000, 500);

        $function = $this->deleteFunction($functionId);
    }

    /**
     * @depends testUpdateFunction
     */
    public function testCreateDeployment($data): array
    {
        /**
         * Test for SUCCESS
         */
        $functionId = $data['functionId'];

        $deployment = $this->createDeployment($functionId, [
            'code' => $this->packageFunction('php'),
            'activate' => true
        ]);

        $this->assertEquals(202, $deployment['headers']['status-code']);
        $this->assertNotEmpty($deployment['body']['$id']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($deployment['body']['$createdAt']));
        $this->assertEquals('index.php', $deployment['body']['entrypoint']);

        $deploymentIdActive = $deployment['body']['$id'] ?? '';

        $this->assertEventually(function () use ($functionId, $deploymentIdActive) {
            $deployment = $this->getDeployment($functionId, $deploymentIdActive);

            $this->assertEquals('ready', $deployment['body']['status']);
        }, 50000, 500);

        $deployment = $this->createDeployment($functionId, [
            'code' => $this->packageFunction('php'),
            'activate' => 'false'
        ]);

        $this->assertEquals(202, $deployment['headers']['status-code']);
        $this->assertNotEmpty($deployment['body']['$id']);

        $deploymentIdInactive = $deployment['body']['$id'] ?? '';

        $this->assertEventually(function () use ($functionId, $deploymentIdInactive) {
            $deployment = $this->getDeployment($functionId, $deploymentIdInactive);

            $this->assertEquals('ready', $deployment['body']['status']);
        }, 50000, 500);

        $function = $this->getFunction($functionId);

        $this->assertEquals(200, $function['headers']['status-code']);
        $this->assertEquals($deploymentIdActive, $function['body']['deployment']);
        $this->assertNotEquals($deploymentIdInactive, $function['body']['deployment']);

        $deployment = $this->client->call(Client::METHOD_DELETE, '/functions/' . $functionId . '/deployments/' . $deploymentIdInactive, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(204, $deployment['headers']['status-code']);

        return array_merge($data, ['deploymentId' => $deploymentIdActive]);
    }

    /**
     * @depends testUpdateFunction
     */
    public function testCancelDeploymentBuild($data): void
    {
        $functionId = $data['functionId'];

        $deployment = $this->createDeployment($functionId, [
            'code' => $this->packageFunction('php'),
            'activate' => 'false'
        ]);

        $deploymentId = $deployment['body']['$id'] ?? '';

        $this->assertEquals(202, $deployment['headers']['status-code']);
        $this->assertNotEmpty($deployment['body']['$id']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($deployment['body']['$createdAt']));
        $this->assertEquals('index.php', $deployment['body']['entrypoint']);

        $this->assertEventually(function () use ($functionId, $deploymentId) {
            $deployment = $this->getDeployment($functionId, $deploymentId);

            $this->assertEquals(200, $deployment['headers']['status-code']);
            $this->assertEquals('building', $deployment['body']['status']);
        }, 100000, 250);

        // Cancel the deployment
        $cancel = $this->client->call(Client::METHOD_PATCH, '/functions/' . $functionId . '/deployments/' . $deploymentId . '/build', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $cancel['headers']['status-code']);
        $this->assertEquals('canceled', $cancel['body']['status']);

        /**
         * Build worker still runs the build.
         * 30s sleep gives worker enough time to finish build.
         * After build finished, it should still be canceled, not ready.
         */
        \sleep(30);

        $deployment = $this->getDeployment($functionId, $deploymentId);

        $this->assertEquals(200, $deployment['headers']['status-code']);
        $this->assertEquals('canceled', $deployment['body']['status']);
    }

    /**
     * @depends testUpdateFunction
     */
    public function testCreateDeploymentLarge($data): array
    {
        /**
         * Test for Large Code File SUCCESS
         */
        $functionId = $data['functionId'];

        $folder = 'php-large';
        $code = realpath(__DIR__ . '/../../../resources/functions') . "/$folder/code.tar.gz";
        Console::execute('cd ' . realpath(__DIR__ . "/../../../resources/functions") . "/$folder  && tar --exclude code.tar.gz -czf code.tar.gz .", '', $this->stdout, $this->stderr);

        $chunkSize = 5 * 1024 * 1024;
        $handle = @fopen($code, "rb");
        $mimeType = 'application/x-gzip';
        $counter = 0;
        $size = filesize($code);
        $headers = [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id']
        ];
        $id = '';
        while (!feof($handle)) {
            $curlFile = new \CURLFile('data://' . $mimeType . ';base64,' . base64_encode(@fread($handle, $chunkSize)), $mimeType, 'php-large-fx.tar.gz');
            $headers['content-range'] = 'bytes ' . ($counter * $chunkSize) . '-' . min(((($counter * $chunkSize) + $chunkSize) - 1), $size - 1) . '/' . $size;
            if (!empty($id)) {
                $headers['x-appwrite-id'] = $id;
            }
            $largeTag = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/deployments', array_merge($headers, $this->getHeaders()), [
                'entrypoint' => 'index.php',
                'code' => $curlFile,
                'activate' => true,
                'commands' => 'cp blue.mp4 copy.mp4 && ls -al' // +7MB buildSize
            ]);
            $counter++;
            $id = $largeTag['body']['$id'];
        }
        @fclose($handle);

        $this->assertEquals(202, $largeTag['headers']['status-code']);
        $this->assertNotEmpty($largeTag['body']['$id']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($largeTag['body']['$createdAt']));
        $this->assertEquals('index.php', $largeTag['body']['entrypoint']);
        $this->assertGreaterThan(1024 * 1024 * 5, $largeTag['body']['size']); // ~7MB video file
        $this->assertLessThan(1024 * 1024 * 10, $largeTag['body']['size']); // ~7MB video file

        $deploymentSize = $largeTag['body']['size'];
        $deploymentId = $largeTag['body']['$id'];

        $this->assertEventually(function () use ($functionId, $deploymentId, $deploymentSize) {
            $deployment = $this->getDeployment($functionId, $deploymentId);

            $this->assertEquals(200, $deployment['headers']['status-code']);
            $this->assertEquals('ready', $deployment['body']['status']);
            $this->assertEquals($deploymentSize, $deployment['body']['size']);
            $this->assertGreaterThan(1024 * 1024 * 10, $deployment['body']['buildSize']); // ~7MB video file + 10MB sample file
        }, 500000, 1000);

        return $data;
    }

    /**
     * @depends testCreateDeployment
     */
    public function testUpdateDeployment($data): array
    {
        /**
         * Test for SUCCESS
         */
        $dateValidator = new DatetimeValidator();

        $response = $this->client->call(Client::METHOD_PATCH, '/functions/' . $data['functionId'] . '/deployments/' . $data['deploymentId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals(true, $dateValidator->isValid($response['body']['$createdAt']));
        $this->assertEquals(true, $dateValidator->isValid($response['body']['$updatedAt']));
        $this->assertEquals($data['deploymentId'], $response['body']['deployment']);

        return $data;
    }

    /**
     * @depends testCreateDeployment
     */
    public function testListDeployments(array $data): array
    {
        /**
         * Test for SUCCESS
         */
        $functionId = $data['functionId'];
        $deployments = $this->listDeployments($functionId);

        $this->assertEquals($deployments['headers']['status-code'], 200);
        $this->assertEquals($deployments['body']['total'], 3);
        $this->assertIsArray($deployments['body']['deployments']);
        $this->assertCount(3, $deployments['body']['deployments']);
        $this->assertArrayHasKey('size', $deployments['body']['deployments'][0]);
        $this->assertArrayHasKey('buildSize', $deployments['body']['deployments'][0]);

        $deployments = $this->listDeployments($functionId, [
            'queries' => [
                Query::limit(1)->toString(),
            ],
        ]);

        $this->assertEquals($deployments['headers']['status-code'], 200);
        $this->assertCount(1, $deployments['body']['deployments']);

        $deployments = $this->listDeployments($functionId, [
            'queries' => [
                Query::offset(1)->toString(),
            ],
        ]);

        $this->assertEquals($deployments['headers']['status-code'], 200);
        $this->assertCount(2, $deployments['body']['deployments']);

        $deployments = $this->listDeployments($functionId, [
            'queries' => [
                Query::equal('entrypoint', ['index.php'])->toString(),
            ],
        ]);

        $this->assertEquals($deployments['headers']['status-code'], 200);
        $this->assertCount(3, $deployments['body']['deployments']);

        $deployments = $this->listDeployments($functionId, [
            'queries' => [
                Query::equal('entrypoint', ['index.js'])->toString(),
            ],
        ]);

        $this->assertEquals($deployments['headers']['status-code'], 200);
        $this->assertCount(0, $deployments['body']['deployments']);

        $deployments = $this->listDeployments($functionId, [
            'search' => 'php-8.0'
        ]);

        $this->assertEquals($deployments['headers']['status-code'], 200);
        $this->assertEquals(3, $deployments['body']['total']);
        $this->assertIsArray($deployments['body']['deployments']);
        $this->assertCount(3, $deployments['body']['deployments']);
        $this->assertEquals($deployments['body']['deployments'][0]['$id'], $data['deploymentId']);

        $deployments = $this->listDeployments(
            $functionId,
            [
                'queries' => [
                    Query::equal('type', ['manual'])->toString(),
                ],
            ]
        );

        $this->assertEquals($deployments['headers']['status-code'], 200);
        $this->assertEquals(3, $deployments['body']['total']);

        $deployments = $this->listDeployments(
            $functionId,
            [
                'queries' => [
                    Query::equal('type', ['vcs'])->toString(),
                ],
            ]
        );

        $this->assertEquals($deployments['headers']['status-code'], 200);
        $this->assertEquals(0, $deployments['body']['total']);

        $deployments = $this->listDeployments(
            $functionId,
            [
                'queries' => [
                    Query::equal('type', ['invalid-string'])->toString(),
                ],
            ]
        );

        $this->assertEquals($deployments['headers']['status-code'], 200);
        $this->assertEquals(0, $deployments['body']['total']);

        $deployments = $this->listDeployments(
            $functionId,
            [
                'queries' => [
                    Query::greaterThan('size', 10000)->toString(),
                ],
            ]
        );

        $this->assertEquals($deployments['headers']['status-code'], 200);
        $this->assertEquals(1, $deployments['body']['total']);

        $deployments = $this->listDeployments(
            $functionId,
            [
                'queries' => [
                    Query::greaterThan('size', 0)->toString(),
                ],
            ]
        );

        $this->assertEquals($deployments['headers']['status-code'], 200);
        $this->assertEquals(3, $deployments['body']['total']);

        $deployments = $this->listDeployments(
            $functionId,
            [
                'queries' => [
                    Query::greaterThan('size', -100)->toString(),
                ],
            ]
        );
        $this->assertEquals($deployments['headers']['status-code'], 200);
        $this->assertEquals(3, $deployments['body']['total']);

        /**
         * Ensure size output and size filters work exactly.
         * Prevents buildSize being counted towards deployemtn size
         */
        $deployments = $this->listDeployments(
            $functionId,
            [
                Query::limit(1)->toString(),
            ]
        );

        $this->assertEquals(200, $deployments['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $deployments['body']['total']);
        $this->assertNotEmpty($deployments['body']['deployments'][0]['$id']);
        $this->assertNotEmpty($deployments['body']['deployments'][0]['size']);

        $deploymentId = $deployments['body']['deployments'][0]['$id'];
        $deploymentSize = $deployments['body']['deployments'][0]['size'];

        $deployments = $this->listDeployments(
            $functionId,
            [
                'queries' => [
                    Query::equal('size', [$deploymentSize])->toString(),
                ],
            ]
        );

        $this->assertEquals(200, $deployments['headers']['status-code']);
        $this->assertGreaterThan(0, $deployments['body']['total']);

        $matchingDeployment = array_filter(
            $deployments['body']['deployments'],
            fn ($deployment) => $deployment['$id'] === $deploymentId
        );

        $this->assertNotEmpty($matchingDeployment, "Deployment with ID {$deploymentId} not found");

        if (!empty($matchingDeployment)) {
            $deployment = reset($matchingDeployment);
            $this->assertEquals($deploymentSize, $deployment['size']);
        }

        return $data;
    }

    /**
     * @depends testCreateDeployment
     */
    public function testGetDeployment(array $data): array
    {
        /**
         * Test for SUCCESS
         */
        $deployment = $this->getDeployment($data['functionId'], $data['deploymentId']);

        $this->assertEquals(200, $deployment['headers']['status-code']);
        $this->assertGreaterThan(0, $deployment['body']['buildTime']);
        $this->assertNotEmpty($deployment['body']['status']);
        $this->assertNotEmpty($deployment['body']['buildLogs']);
        $this->assertArrayHasKey('size', $deployment['body']);
        $this->assertArrayHasKey('buildSize', $deployment['body']);

        /**
         * Test for FAILURE
         */
        $deployment = $this->getDeployment($data['functionId'], 'x');

        $this->assertEquals($deployment['headers']['status-code'], 404);

        return $data;
    }

    /**
     * @depends testUpdateDeployment
     */
    public function testCreateExecution($data): array
    {
        /**
         * Test for SUCCESS
         */
        $execution = $this->createExecution($data['functionId'], [
            'async' => 'false',
        ]);

        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertNotEmpty($execution['body']['$id']);
        $this->assertNotEmpty($execution['body']['functionId']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($execution['body']['$createdAt']));
        $this->assertEquals($data['functionId'], $execution['body']['functionId']);
        $this->assertEquals('completed', $execution['body']['status']);
        $this->assertEquals(200, $execution['body']['responseStatusCode']);
        $this->assertStringContainsString($execution['body']['functionId'], $execution['body']['responseBody']);
        $this->assertStringContainsString($data['deploymentId'], $execution['body']['responseBody']);
        $this->assertStringContainsString('Test1', $execution['body']['responseBody']);
        $this->assertStringContainsString('http', $execution['body']['responseBody']);
        $this->assertStringContainsString('PHP', $execution['body']['responseBody']);
        $this->assertStringContainsString('8.0', $execution['body']['responseBody']);
        $this->assertStringContainsString('Global Variable Value', $execution['body']['responseBody']);
        // $this->assertStringContainsString('êä', $execution['body']['responseBody']); // tests unknown utf-8 chars
        $this->assertNotEmpty($execution['body']['errors']);
        $this->assertNotEmpty($execution['body']['logs']);
        $this->assertLessThan(10, $execution['body']['duration']);

        $executionId = $execution['body']['$id'] ?? '';

        $execution = $this->createExecution($data['functionId'], [
            'async' => 'false',
            'path' => '/?code=400'
        ]);

        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertEquals('completed', $execution['body']['status']);
        $this->assertEquals(400, $execution['body']['responseStatusCode']);

        $execution = $this->client->call(Client::METHOD_DELETE, '/functions/' . $data['functionId'] . '/executions/' . $execution['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(204, $execution['headers']['status-code']);

        return array_merge($data, ['executionId' => $executionId]);
    }

    /**
     * @depends testCreateExecution
     */
    public function testListExecutions(array $data): array
    {
        /**
         * Test for SUCCESS
         */
        $executions = $this->listExecutions($data['functionId']);

        $this->assertEquals(200, $executions['headers']['status-code']);
        $this->assertEquals(1, $executions['body']['total']);
        $this->assertIsArray($executions['body']['executions']);
        $this->assertCount(1, $executions['body']['executions']);

        $executions = $this->listExecutions($data['functionId'], [
            'queries' => [
                Query::limit(1)->toString(),
            ],
        ]);

        $this->assertEquals(200, $executions['headers']['status-code']);
        $this->assertCount(1, $executions['body']['executions']);

        $executions = $this->listExecutions($data['functionId'], [
            'queries' => [
                Query::offset(0)->toString(),
            ],
        ]);

        $this->assertEquals(200, $executions['headers']['status-code']);
        $this->assertCount(1, $executions['body']['executions']);

        $executions = $this->listExecutions($data['functionId'], [
            'queries' => [
                Query::equal('trigger', ['http'])->toString(),
            ],
        ]);

        $this->assertEquals(200, $executions['headers']['status-code']);
        $this->assertCount(1, $executions['body']['executions']);

        /**
         * Test search queries
         */
        $executions = $this->listExecutions($data['functionId'], [
            'search' => $data['executionId'],
        ]);

        $this->assertEquals(200, $executions['headers']['status-code']);
        $this->assertEquals(1, $executions['body']['total']);
        $this->assertIsInt($executions['body']['total']);
        $this->assertCount(1, $executions['body']['executions']);
        $this->assertEquals($data['functionId'], $executions['body']['executions'][0]['functionId']);

        $executions = $this->listExecutions($data['functionId'], [
            'search' => $data['functionId'],
        ]);

        $this->assertEquals(200, $executions['headers']['status-code']);
        $this->assertEquals(1, $executions['body']['total']);
        $this->assertIsInt($executions['body']['total']);
        $this->assertCount(1, $executions['body']['executions']);
        $this->assertEquals($data['executionId'], $executions['body']['executions'][0]['$id']);

        return $data;
    }

    /**
     * @depends testUpdateDeployment
     */
    public function testSyncCreateExecution($data): array
    {
        /**
         * Test for SUCCESS
         */
        $execution = $this->createExecution($data['functionId'], [
            // Testing default value, should be 'async' => 'false'
        ]);

        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertEquals('completed', $execution['body']['status']);
        $this->assertEquals(200, $execution['body']['responseStatusCode']);
        $this->assertStringContainsString('Test1', $execution['body']['responseBody']);
        $this->assertStringContainsString('http', $execution['body']['responseBody']);
        $this->assertStringContainsString('PHP', $execution['body']['responseBody']);
        $this->assertStringContainsString('8.0', $execution['body']['responseBody']);
        // $this->assertStringContainsString('êä', $execution['body']['response']); // tests unknown utf-8 chars
        $this->assertLessThan(1.500, $execution['body']['duration']);

        return $data;
    }

    /**
     * @depends testListExecutions
     */
    public function testGetExecution(array $data): array
    {
        /**
         * Test for SUCCESS
         */
        $execution = $this->getExecution($data['functionId'], $data['executionId']);

        $this->assertEquals($execution['headers']['status-code'], 200);
        $this->assertEquals($execution['body']['$id'], $data['executionId']);

        /**
         * Test for FAILURE
         */
        $function = $this->getExecution($data['functionId'], 'x');

        $this->assertEquals($function['headers']['status-code'], 404);

        return $data;
    }


    /**
     * @depends testGetExecution
     */
    public function testDeleteExecution($data): array
    {
        /**
         * Test for SUCCESS
         */
        $execution = $this->client->call(Client::METHOD_DELETE, '/functions/' . $data['functionId'] . '/executions/' . $data['executionId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $execution['headers']['status-code']);
        $this->assertEmpty($execution['body']);

        $execution = $this->client->call(Client::METHOD_DELETE, '/functions/' . $data['functionId'] . '/executions/' . $data['executionId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $execution['headers']['status-code']);
        $this->assertStringContainsString('Execution with the requested ID could not be found', $execution['body']['message']);

        /**
         * Test for FAILURE
         */
        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $data['functionId'] . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'async' => true,
        ]);

        $executionId = $execution['body']['$id'] ?? '';

        $this->assertEquals(202, $execution['headers']['status-code']);

        $execution = $this->client->call(Client::METHOD_DELETE, '/functions/' . $data['functionId'] . '/executions/' . $executionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(400, $execution['headers']['status-code']);
        $this->assertStringContainsString('execution_in_progress', $execution['body']['type']);
        $this->assertStringContainsString('Can\'t delete ongoing execution.', $execution['body']['message']);

        return $data;
    }



    /**
     * @depends testGetExecution
     */
    public function testUpdateSpecs($data): array
    {
        /**
         * Test for SUCCESS
         */
        // Change the function specs
        $function = $this->client->call(Client::METHOD_PUT, '/functions/' . $data['functionId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Test1',
            'events' => [
                'users.*.update.name',
                'users.*.update.email',
            ],
            'timeout' => 15,
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'specification' => Specification::S_1VCPU_1GB,
        ]);

        $this->assertEquals(200, $function['headers']['status-code']);
        $this->assertNotEmpty($function['body']['$id']);
        $this->assertEquals(Specification::S_1VCPU_1GB, $function['body']['specification']);

        // Verify the updated specs
        $execution = $this->createExecution($data['functionId']);

        $output = json_decode($execution['body']['responseBody'], true);

        $this->assertEquals(1, $output['APPWRITE_FUNCTION_CPUS']);
        $this->assertEquals(1024, $output['APPWRITE_FUNCTION_MEMORY']);

        // Change the specs to 1vcpu 512mb
        $function = $this->client->call(Client::METHOD_PUT, '/functions/' . $data['functionId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Test1',
            'events' => [
                'users.*.update.name',
                'users.*.update.email',
            ],
            'timeout' => 15,
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'specification' => Specification::S_1VCPU_512MB,
        ]);

        $this->assertEquals(200, $function['headers']['status-code']);
        $this->assertNotEmpty($function['body']['$id']);
        $this->assertEquals(Specification::S_1VCPU_512MB, $function['body']['specification']);

        // Verify the updated specs
        $execution = $this->createExecution($data['functionId']);

        $output = json_decode($execution['body']['responseBody'], true);

        $this->assertEquals(1, $output['APPWRITE_FUNCTION_CPUS']);
        $this->assertEquals(512, $output['APPWRITE_FUNCTION_MEMORY']);

        /**
         * Test for FAILURE
         */
        $function = $this->client->call(Client::METHOD_PUT, '/functions/' . $data['functionId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Test1',
            'events' => [
                'users.*.update.name',
                'users.*.update.email',
            ],
            'timeout' => 15,
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'specification' => 's-2vcpu-512mb', // Invalid specification
        ]);

        $this->assertEquals(400, $function['headers']['status-code']);
        $this->assertStringStartsWith('Invalid `specification` param: Specification must be one of:', $function['body']['message']);

        return $data;
    }

    /**
     * @depends testGetExecution
     */
    public function testDeleteDeployment($data): array
    {
        /**
         * Test for SUCCESS
         */
        $deployment = $this->client->call(Client::METHOD_DELETE, '/functions/' . $data['functionId'] . '/deployments/' . $data['deploymentId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $deployment['headers']['status-code']);
        $this->assertEmpty($deployment['body']);

        $deployment = $this->getDeployment($data['functionId'], $data['deploymentId']);

        $this->assertEquals(404, $deployment['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateDeployment
     */
    public function testDeleteFunction($data): array
    {
        /**
         * Test for SUCCESS
         */
        $function = $this->deleteFunction($data['functionId']);

        $this->assertEquals(204, $function['headers']['status-code']);
        $this->assertEmpty($function['body']);

        $function = $this->getFunction($data['functionId']);

        $this->assertEquals(404, $function['headers']['status-code']);

        return $data;
    }

    public function testExecutionTimeout()
    {
        $functionId = $this->setupFunction([
            'functionId' => ID::unique(),
            'name' => 'Test php-8.0',
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'events' => [],
            'schedule' => '',
            'timeout' => 5, // Should timeout after 5 seconds
        ]);
        $this->setupDeployment($functionId, [
            'code' => $this->packageFunction('timeout'),
            'activate' => true,
        ]);

        $execution = $this->createExecution($functionId, [
            'async' => true
        ]);

        $this->assertEquals(202, $execution['headers']['status-code']);

        $executionId = $execution['body']['$id'] ?? '';

        \sleep(5); // Wait for the function to timeout

        $this->assertEventually(function () use ($functionId, $executionId) {
            $execution = $this->getExecution($functionId, $executionId);

            $this->assertEquals(200, $execution['headers']['status-code']);
            $this->assertEquals('failed', $execution['body']['status']);
            $this->assertEquals(500, $execution['body']['responseStatusCode']);
            $this->assertGreaterThan(2, $execution['body']['duration']);
            $this->assertLessThan(20, $execution['body']['duration']);
            $this->assertEquals('', $execution['body']['responseBody']);
            $this->assertEquals('', $execution['body']['logs']);
            $this->assertStringContainsString('timed out', $execution['body']['errors']);
        }, 10000, 500);

        $this->cleanupFunction($functionId);
    }

    /**
     *
     * @return array<mixed>
     */
    public function provideCustomExecutions(): array
    {
        return [
            ['folder' => 'php-fn', 'name' => 'php-8.0', 'entrypoint' => 'index.php', 'runtimeName' => 'PHP', 'runtimeVersion' => '8.0'],
            ['folder' => 'node', 'name' => 'node-18.0', 'entrypoint' => 'index.js', 'runtimeName' => 'Node.js', 'runtimeVersion' => '18.0'],
            ['folder' => 'python', 'name' => 'python-3.9', 'entrypoint' => 'main.py', 'runtimeName' => 'Python', 'runtimeVersion' => '3.9'],
            ['folder' => 'ruby', 'name' => 'ruby-3.1', 'entrypoint' => 'main.rb', 'runtimeName' => 'Ruby', 'runtimeVersion' => '3.1'],
            // Swift and Dart disabled as it's very slow.
            // [ 'folder' => 'dart', 'name' => 'dart-2.15', 'entrypoint' => 'main.dart', 'runtimeName' => 'Dart', 'runtimeVersion' => '2.15' ],
            // [ 'folder' => 'swift', 'name' => 'swift-5.5', 'entrypoint' => 'index.swift', 'runtimeName' => 'Swift', 'runtimeVersion' => '5.5' ],
        ];
    }

    /**
     * @param string $folder
     * @param string $name
     * @param string $entrypoint
     *
     * @dataProvider provideCustomExecutions
     * @depends      testExecutionTimeout
     */
    public function testCreateCustomExecution(string $folder, string $name, string $entrypoint, string $runtimeName, string $runtimeVersion)
    {
        $functionId = $this->setupFunction([
            'functionId' => ID::unique(),
            'name' => 'Test ' . $name,
            'runtime' => $name,
            'entrypoint' => $entrypoint,
            'events' => [],
            'timeout' => 15,
        ]);

        $variable = $this->createVariable($functionId, [
            'key' => 'CUSTOM_VARIABLE',
            'value' => 'variable'
        ]);

        $this->assertEquals(201, $variable['headers']['status-code']);

        $deploymentId = $this->setupDeployment($functionId, [
            'entrypoint' => $entrypoint,
            'code' => $this->packageFunction($folder),
            'activate' => true
        ]);

        $execution = $this->createExecution($functionId, [
            'body' => 'foobar',
            'async' => 'false'
        ]);

        $output = json_decode($execution['body']['responseBody'], true);
        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertEquals('completed', $execution['body']['status']);
        $this->assertEquals(200, $execution['body']['responseStatusCode']);
        $this->assertEquals($functionId, $output['APPWRITE_FUNCTION_ID']);
        $this->assertEquals('Test ' . $name, $output['APPWRITE_FUNCTION_NAME']);
        $this->assertEquals($deploymentId, $output['APPWRITE_FUNCTION_DEPLOYMENT']);
        $this->assertEquals('http', $output['APPWRITE_FUNCTION_TRIGGER']);
        $this->assertEquals($runtimeName, $output['APPWRITE_FUNCTION_RUNTIME_NAME']);
        $this->assertEquals($runtimeVersion, $output['APPWRITE_FUNCTION_RUNTIME_VERSION']);
        $this->assertEquals('', $output['APPWRITE_FUNCTION_EVENT']);
        $this->assertEquals('foobar', $output['APPWRITE_FUNCTION_DATA']);
        $this->assertEquals('variable', $output['CUSTOM_VARIABLE']);
        $this->assertEmpty($output['APPWRITE_FUNCTION_USER_ID']);
        $this->assertEmpty($output['APPWRITE_FUNCTION_JWT']);
        $this->assertEquals($this->getProject()['$id'], $output['APPWRITE_FUNCTION_PROJECT_ID']);
        $this->assertStringContainsString('Amazing Function Log', $execution['body']['logs']);
        $this->assertEmpty($execution['body']['errors']);

        $executionId = $execution['body']['$id'] ?? '';

        $executions = $this->listExecutions($functionId);

        $this->assertEquals($executions['headers']['status-code'], 200);
        $this->assertEquals($executions['body']['total'], 1);
        $this->assertIsArray($executions['body']['executions']);
        $this->assertCount(1, $executions['body']['executions']);
        $this->assertEquals($executions['body']['executions'][0]['$id'], $executionId);
        $this->assertEquals($executions['body']['executions'][0]['trigger'], 'http');
        $this->assertStringContainsString('Amazing Function Log', $executions['body']['executions'][0]['logs']);

        $this->cleanupFunction($functionId);
    }

    public function testCreateCustomExecutionBinaryResponse()
    {
        $functionId = $this->setupFunction([
            'functionId' => ID::unique(),
            'name' => 'Test PHP Binary executions',
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'timeout' => 15,
            'execute' => ['any']
        ]);
        $this->setupDeployment($functionId, [
            'entrypoint' => 'index.php',
            'code' => $this->packageFunction('php-binary-response'),
            'activate' => true
        ]);

        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/executions', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
            'accept' => 'multipart/form-data', // Accept binary response
        ], $this->getHeaders()), [
            'body' => null,
        ]);

        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertStringContainsString('multipart/form-data', $execution['headers']['content-type']);
        $contentType = explode(';', $execution['headers']['content-type']);
        $this->assertStringContainsString('boundary=----', $contentType[1]);
        $bytes = unpack('C*byte', $execution['body']['responseBody']);
        $this->assertCount(3, $bytes);
        $this->assertEquals(0, $bytes['byte1']);
        $this->assertEquals(10, $bytes['byte2']);
        $this->assertEquals(255, $bytes['byte3']);

        /**
         * Test for FAILURE
         */
        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/executions', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
            'accept' => 'application/json', // Accept JSON response
        ], $this->getHeaders()), [
            'body' => null,
        ]);

        $this->assertEquals(400, $execution['headers']['status-code']);
        $this->assertStringContainsString('Failed to parse response', $execution['body']['message']);

        $this->cleanupFunction($functionId);
    }

    public function testCreateCustomExecutionBinaryRequest()
    {
        $functionId = $this->setupFunction([
            'functionId' => ID::unique(),
            'name' => 'Test PHP Binary executions',
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'timeout' => 15,
            'execute' => ['any']
        ]);
        $this->setupDeployment($functionId, [
            'entrypoint' => 'index.php',
            'code' => $this->packageFunction('php-binary-request'),
            'activate' => true
        ]);

        $bytes = pack('C*', ...[0, 20, 255]);

        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/executions', array_merge([
            'content-type' => 'multipart/form-data', // Send binary request
            'x-appwrite-project' => $this->getProject()['$id'],
            'accept' => 'application/json',
        ], $this->getHeaders()), [
            'body' => $bytes,
        ], false);

        $executionBody = json_decode($execution['body'], true);

        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertEquals(\md5($bytes), $executionBody['responseBody']);
        $this->assertStringStartsWith('application/json', $execution['headers']['content-type']);

        /**
         * Test for FAILURE
         */
        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/executions', array_merge([
            'content-type' => 'application/json', // Send JSON headers
            'x-appwrite-project' => $this->getProject()['$id'],
            'accept' => 'application/json',
        ], $this->getHeaders()), [
            'body' => $bytes,
        ], false);

        $executionBody = json_decode($execution['body'], true);

        $this->assertNotEquals(\md5($bytes), $executionBody['responseBody']);

        $this->cleanupFunction($functionId);
    }

    public function testv2Function()
    {
        $functionId = $this->setupFunction([
            'functionId' => ID::unique(),
            'name' => 'Test PHP V2',
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'events' => [],
            'timeout' => 15,
        ]);

        $variable = $this->client->call(Client::METHOD_PATCH, '/mock/functions-v2', [
            'content-type' => 'application/json',
            'origin' => 'http://localhost',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-mode' => 'admin',
        ], [
            'functionId' => $functionId
        ]);
        $this->assertEquals(204, $variable['headers']['status-code']);

        $this->setupDeployment($functionId, [
            'entrypoint' => 'index.php',
            'code' => $this->packageFunction('php-v2'),
            'activate' => true
        ]);

        $execution = $this->createExecution($functionId, [
            'body' => 'foobar',
            'async' => 'false'
        ]);

        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertEquals('completed', $execution['body']['status']);
        $this->assertEquals(200, $execution['body']['responseStatusCode']);

        $output = json_decode($execution['body']['responseBody'], true);
        $this->assertEquals(true, $output['v2Woks']);

        $this->cleanupFunction($functionId);
    }

    public function testGetRuntimes()
    {
        $runtimes = $this->client->call(Client::METHOD_GET, '/functions/runtimes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $runtimes['headers']['status-code']);
        $this->assertGreaterThan(0, $runtimes['body']['total']);

        $runtime = $runtimes['body']['runtimes'][0];

        $this->assertArrayHasKey('$id', $runtime);
        $this->assertArrayHasKey('name', $runtime);
        $this->assertArrayHasKey('key', $runtime);
        $this->assertArrayHasKey('version', $runtime);
        $this->assertArrayHasKey('logo', $runtime);
        $this->assertArrayHasKey('image', $runtime);
        $this->assertArrayHasKey('base', $runtime);
        $this->assertArrayHasKey('supports', $runtime);
    }


    public function testEventTrigger()
    {
        $functionId = $this->setupFunction([
            'functionId' => ID::unique(),
            'name' => 'Test PHP Event executions',
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'events' => [
                'users.*.create',
            ],
            'timeout' => 15,
        ]);
        $this->setupDeployment($functionId, [
            'entrypoint' => 'index.php',
            'code' => $this->packageFunction('php-event'),
            'activate' => true
        ]);

        // Create user as an event trigger
        $user = $this->client->call(Client::METHOD_POST, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => 'unique()',
            'name' => 'Event User'
        ]);

        $this->assertEquals(201, $user['headers']['status-code']);

        $userId = $user['body']['$id'] ?? '';

        $this->assertEventually(function () use ($functionId, $userId) {
            $executions = $this->listExecutions($functionId);

            $lastExecution = $executions['body']['executions'][0];

            $this->assertEquals('completed', $lastExecution['status']);
            $this->assertEquals(204, $lastExecution['responseStatusCode']);
            $this->assertStringContainsString($userId, $lastExecution['logs']);
            $this->assertStringContainsString('Event User', $lastExecution['logs']);
        }, 10000, 500);

        $this->cleanupFunction($functionId);

        // Cleanup user
        $user = $this->client->call(Client::METHOD_DELETE, '/users/' . $userId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], []);
        $this->assertEquals(204, $user['headers']['status-code']);
    }

    public function testScopes()
    {
        $functionId = $this->setupFunction([
            'functionId' => ID::unique(),
            'name' => 'Test PHP Scopes executions',
            'commands' => 'sh setup.sh && composer install',
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'scopes' => ['users.read'],
            'timeout' => 15,
        ]);

        $deploymentId = $this->setupDeployment($functionId, [
            'entrypoint' => 'index.php',
            'code' => $this->packageFunction('php-scopes'),
            'activate' => true,
        ]);

        $deployment = $this->getDeployment($functionId, $deploymentId);

        $this->assertEquals(200, $deployment['headers']['status-code']);
        $this->assertStringContainsStringIgnoringCase("200 OK", $deployment['body']['buildLogs']);
        $this->assertStringContainsStringIgnoringCase('"total":', $deployment['body']['buildLogs']);
        $this->assertStringContainsStringIgnoringCase('"users":', $deployment['body']['buildLogs']);

        $execution = $this->createExecution($functionId, [
            'async' => 'false',
        ]);

        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertEquals('completed', $execution['body']['status']);
        $this->assertEquals(200, $execution['body']['responseStatusCode']);
        $this->assertGreaterThan(0, $execution['body']['duration']);
        $this->assertNotEmpty($execution['body']['responseBody']);
        $this->assertStringContainsString("total", $execution['body']['responseBody']);

        $execution = $this->createExecution($functionId, [
            'async' => true,
        ]);

        $this->assertEquals(202, $execution['headers']['status-code']);
        $this->assertNotEmpty($execution['body']['$id']);

        $executionId = $execution['body']['$id'] ?? '';

        $this->assertEventually(function () use ($functionId, $executionId) {
            $execution = $this->getExecution($functionId, $executionId);

            $this->assertEquals(200, $execution['headers']['status-code']);
            $this->assertEquals('completed', $execution['body']['status']);
            $this->assertEquals(200, $execution['body']['responseStatusCode']);
            $this->assertGreaterThan(0, $execution['body']['duration']);
            $this->assertNotEmpty($execution['body']['logs']);
            $this->assertStringContainsString("total", $execution['body']['logs']);
        }, 10000, 500);

        $this->cleanupFunction($functionId);
    }

    public function testCookieExecution()
    {
        $functionId = $this->setupFunction([
            'functionId' => ID::unique(),
            'name' => 'Test PHP Cookie executions',
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'timeout' => 15,
        ]);
        $this->setupDeployment($functionId, [
            'entrypoint' => 'index.php',
            'code' => $this->packageFunction('php-cookie'),
            'activate' => true
        ]);

        $cookie = 'cookieName=cookieValue; cookie2=value2; cookie3=value=3; cookie4=val:ue4; cookie5=value5';
        $execution = $this->createExecution($functionId, [
            'async' => 'false',
            'headers' => [
                'cookie' => $cookie
            ]
        ]);

        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertEquals('completed', $execution['body']['status']);
        $this->assertEquals(200, $execution['body']['responseStatusCode']);
        $this->assertEquals($cookie, $execution['body']['responseBody']);
        $this->assertGreaterThan(0, $execution['body']['duration']);

        $this->cleanupFunction($functionId);
    }

    public function testFunctionsDomain()
    {
        $functionId = $this->setupFunction([
            'functionId' => ID::unique(),
            'name' => 'Test PHP Cookie executions',
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'timeout' => 15,
            'execute' => ['any']
        ]);

        $rules = $this->client->call(Client::METHOD_GET, '/proxy/rules', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('resourceId', [$functionId])->toString(),
                Query::equal('resourceType', ['function'])->toString(),
            ],
        ]);

        $this->assertEquals(200, $rules['headers']['status-code']);
        $this->assertEquals(1, $rules['body']['total']);
        $this->assertCount(1, $rules['body']['rules']);
        $this->assertNotEmpty($rules['body']['rules'][0]['domain']);

        $domain = $rules['body']['rules'][0]['domain'];

        $this->setupDeployment($functionId, [
            'entrypoint' => 'index.php',
            'code' => $this->packageFunction('php-cookie'),
            'activate' => true
        ]);

        $cookie = 'cookieName=cookieValue; cookie2=value2; cookie3=value=3; cookie4=val:ue4; cookie5=value5';

        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $domain);

        $response = $proxyClient->call(Client::METHOD_GET, '/', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => $cookie
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($cookie, $response['body']);

        // Async execution document creation
        $this->assertEventually(function () use ($functionId) {
            $executions = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/executions', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), []);

            $this->assertEquals(200, $executions['headers']['status-code']);
            $this->assertEquals(1, count($executions['body']['executions']));
        });

        // Await Aggregation
        sleep(System::getEnv('_APP_USAGE_AGGREGATION_INTERVAL', 30));

        $this->assertEventually(function () use ($functionId) {
            $response = $this->getFunctionUsage($functionId, [
                'range' => '24h'
            ]);

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals(19, count($response['body']));
            $this->assertEquals('24h', $response['body']['range']);
            $this->assertEquals(1, $response['body']['executionsTotal']);
        }, 25000, 1000);

        $this->cleanupFunction($functionId);
    }

    public function testFunctionsDomainBinaryResponse()
    {
        $functionId = $this->setupFunction([
            'functionId' => ID::unique(),
            'name' => 'Test PHP Binary executions',
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'timeout' => 15,
            'execute' => ['any']
        ]);

        $rules = $this->client->call(Client::METHOD_GET, '/proxy/rules', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('resourceId', [$functionId])->toString(),
                Query::equal('resourceType', ['function'])->toString(),
            ],
        ]);

        $this->assertEquals(200, $rules['headers']['status-code']);
        $this->assertEquals(1, $rules['body']['total']);
        $this->assertCount(1, $rules['body']['rules']);
        $this->assertNotEmpty($rules['body']['rules'][0]['domain']);

        $domain = $rules['body']['rules'][0]['domain'];

        $this->setupDeployment($functionId, [
            'entrypoint' => 'index.php',
            'code' => $this->packageFunction('php-binary-response'),
            'activate' => true
        ]);

        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $domain);

        $response = $proxyClient->call(Client::METHOD_GET, '/', [], [], false);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $bytes = unpack('C*byte', $response['body']);
        $this->assertCount(3, $bytes);
        $this->assertEquals(0, $bytes['byte1']);
        $this->assertEquals(10, $bytes['byte2']);
        $this->assertEquals(255, $bytes['byte3']);

        $this->cleanupFunction($functionId);
    }

    public function testFunctionsDomainBinaryRequest()
    {
        $functionId = $this->setupFunction([
            'functionId' => ID::unique(),
            'name' => 'Test PHP Binary executions',
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'timeout' => 15,
            'execute' => ['any']
        ]);

        $rules = $this->client->call(Client::METHOD_GET, '/proxy/rules', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('resourceId', [$functionId])->toString(),
                Query::equal('resourceType', ['function'])->toString(),
            ],
        ]);

        $this->assertEquals(200, $rules['headers']['status-code']);
        $this->assertEquals(1, $rules['body']['total']);
        $this->assertCount(1, $rules['body']['rules']);
        $this->assertNotEmpty($rules['body']['rules'][0]['domain']);

        $domain = $rules['body']['rules'][0]['domain'];

        $this->setupDeployment($functionId, [
            'entrypoint' => 'index.php',
            'code' => $this->packageFunction('php-binary-request'),
            'activate' => true
        ]);

        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $domain);

        $bytes = pack('C*', ...[0, 20, 255]);

        $response = $proxyClient->call(Client::METHOD_POST, '/', ['content-type' => 'text/plain'], $bytes, false);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(\md5($bytes), $response['body']);

        $this->cleanupFunction($functionId);
    }

    public function testResponseFilters()
    {
        // create function with 1.5.0 response format
        $response = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-response-format' => '1.5.0', // add response format header
        ], $this->getHeaders()), [
            'functionId' => ID::unique(),
            'name' => 'Test',
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'timeout' => 15,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertArrayNotHasKey('scopes', $response['body']);
        $this->assertArrayNotHasKey('specification', $response['body']);

        // get function with 1.5.0 response format header
        $function = $this->client->call(Client::METHOD_GET, '/functions/' . $response['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-response-format' => '1.5.0', // add response format header
        ], $this->getHeaders()));

        $this->assertEquals(200, $function['headers']['status-code']);
        $this->assertArrayNotHasKey('scopes', $function['body']);
        $this->assertArrayNotHasKey('specification', $function['body']);

        $function = $this->getFunction($function['body']['$id']);

        $this->assertEquals(200, $function['headers']['status-code']);
        $this->assertArrayHasKey('scopes', $function['body']);
        $this->assertArrayHasKey('specification', $function['body']);

        $functionId = $function['body']['$id'] ?? '';
        $this->cleanupFunction($functionId);
    }

    public function testRequestFilters()
    {
        $function1Id = $this->setupFunction([
            'functionId' => ID::unique(),
            'name' => 'Test',
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'timeout' => 15,
            'execute' => ['any']
        ]);

        $function2Id = $this->setupFunction([
            'functionId' => ID::unique(),
            'name' => 'Test2',
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'timeout' => 15,
            'execute' => ['any']
        ]);

        // list functions using request filters
        $response = $this->client->call(
            Client::METHOD_GET,
            '/functions',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-response-format' => '1.4.0', // Set response format for 1.4 syntax
            ], $this->getHeaders()),
            [
                'queries' => [ 'equal("name", ["Test2"])' ]
            ]
        );

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(1, $response['body']['functions']);
        $this->assertEquals('Test2', $response['body']['functions'][0]['name']);

        $this->cleanupFunction($function1Id);
        $this->cleanupFunction($function2Id);
    }

    public function testFunctionLogging()
    {
        $function = $this->createFunction([
            'functionId' => ID::unique(),
            'runtime' => 'node-18.0',
            'name' => 'Logging Test',
            'entrypoint' => 'index.js',
            'logging' => false,
            'execute' => ['any']
        ]);

        $this->assertEquals(201, $function['headers']['status-code']);
        $this->assertFalse($function['body']['logging']);
        $this->assertNotEmpty($function['body']['$id']);

        $functionId = $functionId = $function['body']['$id'] ?? '';

        $this->setupDeployment($functionId, [
            'code' => $this->packageFunction('node'),
            'activate' => true
        ]);

        // Sync Executions test
        $execution = $this->createExecution($functionId);

        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertEmpty($execution['body']['logs']);
        $this->assertEmpty($execution['body']['errors']);

        // Async Executions test
        $execution = $this->createExecution($functionId, [
            'async' => true
        ]);

        $this->assertEquals(202, $execution['headers']['status-code']);
        $this->assertEmpty($execution['body']['logs']);
        $this->assertEmpty($execution['body']['errors']);
        $this->assertNotEmpty($execution['body']['$id']);

        $executionId = $execution['body']['$id'] ?? '';

        $this->assertEventually(function () use ($functionId, $executionId) {
            $execution = $this->getExecution($functionId, $executionId);

            $this->assertEquals(200, $execution['headers']['status-code']);
            $this->assertEquals('completed', $execution['body']['status']);
            $this->assertEmpty($execution['body']['logs']);
            $this->assertEmpty($execution['body']['errors']);
        }, 10000, 500);

        // Domain Executions test
        $rules = $this->client->call(Client::METHOD_GET, '/proxy/rules', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('resourceId', [$functionId])->toString(),
                Query::equal('resourceType', ['function'])->toString(),
            ],
        ]);

        $this->assertEquals(200, $rules['headers']['status-code']);
        $this->assertNotEmpty($rules['body']['rules'][0]['domain']);

        $domain = $rules['body']['rules'][0]['domain'];

        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $domain);

        $response = $proxyClient->call(Client::METHOD_GET, '/', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);

        $executions = $this->listExecutions($functionId, [
            'queries' => [
                Query::limit(1)->toString(),
                Query::orderDesc('$id')->toString(),
            ]
        ]);

        $this->assertEquals(200, $executions['headers']['status-code']);
        $this->assertCount(1, $executions['body']['executions']);
        $this->assertEmpty($executions['body']['executions'][0]['logs']);
        $this->assertEmpty($executions['body']['executions'][0]['errors']);

        // Ensure executions count
        $executions = $this->listExecutions($functionId);

        $this->assertEquals(200, $executions['headers']['status-code']);
        $this->assertCount(3, $executions['body']['executions']);

        // Double check logs and errors are empty
        foreach ($executions['body']['executions'] as $execution) {
            $this->assertEmpty($execution['logs']);
            $this->assertEmpty($execution['errors']);
        }

        $this->cleanupFunction($functionId);
    }
}
