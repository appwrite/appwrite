<?php

namespace Tests\E2E\Services\Functions;

use Appwrite\Functions\Specification;
use Appwrite\Tests\Retry;
use CURLFile;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\App;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Datetime as DatetimeValidator;

class FunctionsCustomServerTest extends Scope
{
    use FunctionsBase;
    use ProjectCustom;
    use SideServer;

    public function testCreate(): array
    {
        /**
         * Test for SUCCESS
         */
        $response1 = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'functionId' => ID::unique(),
            'name' => 'Test',
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'events' => [
                'buckets.*.create',
                'buckets.*.delete',
            ],
            'schedule' => '0 0 1 1 *',
            'timeout' => 10,
        ]);

        $functionId = $response1['body']['$id'] ?? '';

        $this->assertEquals(201, $response1['headers']['status-code']);
        $this->assertNotEmpty($response1['body']['$id']);
        $this->assertEquals('Test', $response1['body']['name']);
        $this->assertEquals('php-8.0', $response1['body']['runtime']);
        $dateValidator = new DatetimeValidator();
        $this->assertEquals(true, $dateValidator->isValid($response1['body']['$createdAt']));
        $this->assertEquals(true, $dateValidator->isValid($response1['body']['$updatedAt']));
        $this->assertEquals('', $response1['body']['deployment']);
        $this->assertEquals([
            'buckets.*.create',
            'buckets.*.delete',
        ], $response1['body']['events']);
        $this->assertEquals('0 0 1 1 *', $response1['body']['schedule']);
        $this->assertEquals(10, $response1['body']['timeout']);

        /** Create Variables */
        $variable = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => 'funcKey1',
            'value' => 'funcValue1',
        ]);

        $variable2 = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => 'funcKey2',
            'value' => 'funcValue2',
        ]);

        $variable3 = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => 'funcKey3',
            'value' => 'funcValue3',
        ]);

        $this->assertEquals(201, $variable['headers']['status-code']);
        $this->assertEquals(201, $variable2['headers']['status-code']);
        $this->assertEquals(201, $variable3['headers']['status-code']);

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
    public function testList(array $data): array
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
            'queries' => [
                Query::limit(1)->toString(),
            ],
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertCount(1, $response['body']['functions']);

        $response = $this->client->call(Client::METHOD_GET, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::offset(1)->toString(),
            ],
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertCount(0, $response['body']['functions']);

        $response = $this->client->call(Client::METHOD_GET, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('enabled', [true])->toString(),
            ],
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertCount(1, $response['body']['functions']);

        $response = $this->client->call(Client::METHOD_GET, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('enabled', [false])->toString(),
            ],
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertCount(0, $response['body']['functions']);

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
            'functionId' => ID::unique(),
            'name' => 'Test 2',
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'events' => [
                'buckets.*.create',
                'buckets.*.delete',
            ],
            'schedule' => '0 0 1 1 *',
            'timeout' => 10,
        ]);
        $this->assertNotEmpty($response['body']['$id']);

        /** Create Variables */
        $variable = $this->client->call(Client::METHOD_POST, '/functions/' . $response['body']['$id'] . '/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => 'funcKey1',
            'value' => 'funcValue1',
        ]);

        $variable2 = $this->client->call(Client::METHOD_POST, '/functions/' . $response['body']['$id'] . '/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => 'funcKey2',
            'value' => 'funcValue2',
        ]);

        $variable3 = $this->client->call(Client::METHOD_POST, '/functions/' . $response['body']['$id'] . '/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => 'funcKey3',
            'value' => 'funcValue3',
        ]);

        $this->assertEquals(201, $variable['headers']['status-code']);
        $this->assertEquals(201, $variable2['headers']['status-code']);
        $this->assertEquals(201, $variable3['headers']['status-code']);

        $functions = $this->client->call(Client::METHOD_GET, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($functions['headers']['status-code'], 200);
        $this->assertEquals($functions['body']['total'], 2);
        $this->assertIsArray($functions['body']['functions']);
        $this->assertCount(2, $functions['body']['functions']);
        $this->assertEquals($functions['body']['functions'][0]['name'], 'Test');
        $this->assertEquals($functions['body']['functions'][1]['name'], 'Test 2');

        $response = $this->client->call(Client::METHOD_GET, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorAfter(new Document(['$id' => $functions['body']['functions'][0]['$id']]))->toString(),
            ],
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertCount(1, $response['body']['functions']);
        $this->assertEquals($response['body']['functions'][0]['name'], 'Test 2');

        $response = $this->client->call(Client::METHOD_GET, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorBefore(new Document(['$id' => $functions['body']['functions'][1]['$id']]))->toString(),
            ],
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertCount(1, $response['body']['functions']);
        $this->assertEquals($response['body']['functions'][0]['name'], 'Test');

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorAfter(new Document(['$id' => 'unknown']))->toString(),
            ],
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        return $data;
    }

    /**
     * @depends testList
     */
    public function testGet(array $data): array
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
    public function testUpdate($data): array
    {
        /**
         * Test for SUCCESS
         */
        $response1 = $this->client->call(Client::METHOD_PUT, '/functions/' . $data['functionId'], array_merge([
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

        $this->assertEquals(200, $response1['headers']['status-code']);
        $this->assertNotEmpty($response1['body']['$id']);
        $this->assertEquals('Test1', $response1['body']['name']);
        $dateValidator = new DatetimeValidator();
        $this->assertEquals(true, $dateValidator->isValid($response1['body']['$createdAt']));
        $this->assertEquals(true, $dateValidator->isValid($response1['body']['$updatedAt']));
        $this->assertEquals('', $response1['body']['deployment']);
        $this->assertEquals([
            'users.*.update.name',
            'users.*.update.email',
        ], $response1['body']['events']);
        $this->assertEquals('0 0 1 1 *', $response1['body']['schedule']);
        $this->assertEquals(15, $response1['body']['timeout']);

        /**
         * Create global variable to test in execution later
         */
        $headers = [
            'content-type' => 'application/json',
            'origin' => 'http://localhost',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-mode' => 'admin',
        ];

        $variable = $this->client->call(Client::METHOD_POST, '/project/variables', $headers, [
            'key' => 'GLOBAL_VARIABLE',
            'value' => 'Global Variable Value',
        ]);

        $this->assertEquals(201, $variable['headers']['status-code']);

        /**
         * Test for FAILURE
         */

        return $data;
    }

    public function testCreateDeploymentFromCLI()
    {
        $function = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'functionId' => ID::unique(),
            'name' => 'Test',
            'execute' => [Role::user($this->getUser()['$id'])->toString()],
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'events' => [
                'users.*.create',
                'users.*.delete',
            ],
            'schedule' => '0 0 1 1 *',
            'timeout' => 10,
        ]);

        $this->assertEquals(201, $function['headers']['status-code']);

        $functionId = $function['body']['$id'];

        $folder = 'php';
        $code = realpath(__DIR__ . '/../../../resources/functions') . "/$folder/code.tar.gz";
        $this->packageCode($folder);

        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $function['body']['$id'] . '/deployments', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
            'x-sdk-language' => 'cli',
        ], [
            'entrypoint' => 'index.php',
            'code' => new CURLFile($code, 'application/x-gzip', \basename($code)),
            'activate' => true
        ]);

        $deploymentId = $deployment['body']['$id'] ?? '';

        $this->assertEquals(202, $deployment['headers']['status-code']);

        $this->awaitDeploymentIsBuilt($function['body']['$id'], $deploymentId);

        $functionDetails = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/deployments/' . $deploymentId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], []);

        $this->assertEquals(200, $functionDetails['headers']['status-code']);
        $this->assertEquals('cli', $functionDetails['body']['type']);
    }

    public function testCreateDeploymentFromTemplate()
    {
        $runtimeName = 'php-8.0';

        // Fetch starter template (used to create function later)
        $template = $this->client->call(Client::METHOD_GET, '/functions/templates/starter', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $template['headers']['status-code']);

        $entrypoint = null;
        $rootDirectory = null;
        $commands = null;
        foreach ($template['body']['runtimes'] as $runtime) {
            if ($runtime["name"] !== $runtimeName) {
                continue;
            }

            $entrypoint = $runtime["entrypoint"];
            $rootDirectory = $runtime["providerRootDirectory"];
            $commands = $runtime["commands"];
            break;
        }

        $this->assertNotNull($entrypoint);

        /**
         * If below test ever starts failing, it means temaplate used in
         * this test now has some variables. This test currently doesnt test variables.
         * Remove bellow assertion and update test to crete variable,
         * and ensure variable works as expected in execution.
         */
        $this->assertEmpty($template['body']['variables']);

        // Create function using settings from template.
        // Deployment is automatically created from template inside endpoint
        $function = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $this->getHeaders()), [
            'functionId' => ID::unique(),
            'name' => $template['body']['name'],
            'runtime' => $runtimeName,
            'execute' => $template['body']['permissions'],
            'entrypoint' => $entrypoint,
            'events' => $template['body']['events'],
            'schedule' => $template['body']['cron'],
            'timeout' => $template['body']['timeout'],
            'commands' => $commands,
            'scopes' => $template['body']['scopes'],
            'templateRepository' => $template['body']['providerRepositoryId'],
            'templateOwner' => $template['body']['providerOwner'],
            'templateRootDirectory' => $rootDirectory,
            'templateVersion' => $template['body']['providerVersion'],
        ]);

        $this->assertEquals(201, $function['headers']['status-code']);
        $this->assertNotEmpty($function['body']['$id']);

        $functionId = $function['body']['$id'];

        // List deployments so we can await deployment build
        $deployments = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/deployments', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], []);

        $this->assertEquals(200, $deployments['headers']['status-code']);
        $this->assertEquals(1, $deployments['body']['total']);
        $this->assertNotEmpty($deployments['body']['deployments'][0]['$id']);
        $this->assertEquals(0, $deployments['body']['deployments'][0]['size']);

        $deploymentId = $deployments['body']['deployments'][0]['$id'];

        // Wait for deployment build to finish
        // Deployment is automatically activated
        $this->awaitDeploymentIsBuilt($function['body']['$id'], $deploymentId);

        $deployments = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/deployments/' . $deploymentId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], []);
        $this->assertGreaterThan(0, $deployments['body']['size']);

        $function = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $function['headers']['status-code']);
        $this->assertEquals($deploymentId, $function['body']['deployment']);

        // Execute function to ensure starter code is used
        // Also tests if dynamic keys works as expected
        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'path' => '/ping'
        ]);

        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertEquals("completed", $execution['body']['status']);
        $this->assertEquals(200, $execution['body']['responseStatusCode']);
        $this->assertEquals("Pong", $execution['body']['responseBody']);
        $this->assertEmpty($execution['body']['errors']);

        // Get users to ensure execution logged correct total users
        $users = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $users['headers']['status-code']);
        $this->assertIsInt($users['body']['total']);

        $totalusers = $users['body']['total'];

        $this->assertStringContainsString("Total users: " . $totalusers, $execution['body']['logs']);

        // Cleanup : Delete function
        $response = $this->client->call(Client::METHOD_DELETE, '/functions/' . $functionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], []);

        $this->assertEquals(204, $response['headers']['status-code']);
    }

    /**
     * @depends testUpdate
     */
    public function testCreateDeployment($data): array
    {
        /**
         * Test for SUCCESS
         */
        $folder = 'php';
        $code = realpath(__DIR__ . '/../../../resources/functions') . "/$folder/code.tar.gz";
        $this->packageCode($folder);

        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $data['functionId'] . '/deployments', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'code' => new CURLFile($code, 'application/x-gzip', \basename($code)),
            'activate' => true
        ]);

        $deploymentId = $deployment['body']['$id'] ?? '';

        $this->assertEquals(202, $deployment['headers']['status-code']);
        $this->assertNotEmpty($deployment['body']['$id']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($deployment['body']['$createdAt']));
        $this->assertEquals('index.php', $deployment['body']['entrypoint']);

        $this->awaitDeploymentIsBuilt($data['functionId'], $deploymentId);

        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $data['functionId'] . '/deployments', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'code' => new CURLFile($code, 'application/x-gzip', \basename($code)),
            'activate' => 'false'
        ]);

        $this->assertEquals(202, $deployment['headers']['status-code']);
        $this->assertNotEmpty($deployment['body']['$id']);

        $deploymentIdInactive = $deployment['body']['$id'];

        $this->awaitDeploymentIsBuilt($data['functionId'], $deploymentIdInactive);

        $function = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $function['headers']['status-code']);
        $this->assertEquals($deploymentId, $function['body']['deployment']);
        $this->assertNotEquals($deploymentIdInactive, $function['body']['deployment']);

        $deployment = $this->client->call(Client::METHOD_DELETE, '/functions/' . $data['functionId'] . '/deployments/' . $deploymentIdInactive, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(204, $deployment['headers']['status-code']);

        return array_merge($data, ['deploymentId' => $deploymentId]);
    }

    /**
     * @depends testUpdate
     */
    public function testCancelDeploymentBuild($data): void
    {
        // Create a new deployment to cancel
        $folder = 'php';
        $code = realpath(__DIR__ . '/../../../resources/functions') . "/$folder/code.tar.gz";
        $this->packageCode($folder);

        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $data['functionId'] . '/deployments', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'code' => new CURLFile($code, 'application/x-gzip', \basename($code)),
            'activate' => true
        ]);

        $deploymentId = $deployment['body']['$id'] ?? '';

        $this->assertEquals(202, $deployment['headers']['status-code']);
        $this->assertNotEmpty($deployment['body']['$id']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($deployment['body']['$createdAt']));
        $this->assertEquals('index.php', $deployment['body']['entrypoint']);

        // Poll until deployment is in progress
        while (true) {
            $deployment = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/deployments/' . $deploymentId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);

            if (
                $deployment['headers']['status-code'] >= 400
                || $deployment['body']['status'] === 'building'
            ) {
                break;
            }

            \sleep(1);
        }

        $this->assertEquals(200, $deployment['headers']['status-code']);
        $this->assertEquals('building', $deployment['body']['status']);

        // Cancel the deployment build
        $cancel = $this->client->call(Client::METHOD_PATCH, '/functions/' . $data['functionId'] . '/deployments/' . $deploymentId . '/build', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $cancel['headers']['status-code']);
        $this->assertEquals('canceled', $cancel['body']['status']);

        /**
         * Build worker still runs the build.
         * 30s sleep gives worker enough time to finish build.
         * After build finished, it should still be canceled, not ready.
         */
        \sleep(30);
        $deployment = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/deployments/' . $deploymentId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $deployment['headers']['status-code']);
        $this->assertEquals('canceled', $deployment['body']['status']);
    }

    /**
     * @depends testUpdate
     */
    public function testCreateDeploymentLarge($data): array
    {
        /**
         * Test for Large Code File SUCCESS
         */

        $folder = 'php-large';
        $code = realpath(__DIR__ . '/../../../resources/functions') . "/$folder/code.tar.gz";
        $this->packageCode($folder);

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
            $largeTag = $this->client->call(Client::METHOD_POST, '/functions/' . $data['functionId'] . '/deployments', array_merge($headers, $this->getHeaders()), [
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

        $this->awaitDeploymentIsBuilt($data['functionId'], $deploymentId, true);

        $response = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/deployments/' . $deploymentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($deploymentSize, $response['body']['size']);
        $this->assertGreaterThan(1024 * 1024 * 10, $response['body']['buildSize']); // ~7MB video file + 10MB sample file

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
        $response = $this->client->call(Client::METHOD_PATCH, '/functions/' . $data['functionId'] . '/deployments/' . $data['deploymentId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $dateValidator = new DatetimeValidator();
        $this->assertEquals(true, $dateValidator->isValid($response['body']['$createdAt']));
        $this->assertEquals(true, $dateValidator->isValid($response['body']['$updatedAt']));
        $this->assertEquals($data['deploymentId'], $response['body']['deployment']);

        /**
         * Test for FAILURE
         */

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
        $function = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/deployments', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($function['headers']['status-code'], 200);
        $this->assertEquals($function['body']['total'], 3);
        $this->assertIsArray($function['body']['deployments']);
        $this->assertCount(3, $function['body']['deployments']);
        $this->assertArrayHasKey('size', $function['body']['deployments'][0]);
        $this->assertArrayHasKey('buildSize', $function['body']['deployments'][0]);

        /**
         * Test search queries
         */
        $function = $this->client->call(
            Client::METHOD_GET,
            '/functions/' . $data['functionId'] . '/deployments',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders(), [
                'search' => $data['functionId']
            ])
        );

        $this->assertEquals($function['headers']['status-code'], 200);
        $this->assertEquals(3, $function['body']['total']);
        $this->assertIsArray($function['body']['deployments']);
        $this->assertCount(3, $function['body']['deployments']);
        $this->assertEquals($function['body']['deployments'][0]['$id'], $data['deploymentId']);

        $function = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/deployments', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::limit(1)->toString(),
            ],
        ]);

        $this->assertEquals($function['headers']['status-code'], 200);
        $this->assertCount(1, $function['body']['deployments']);

        $function = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/deployments', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::offset(1)->toString(),
            ],
        ]);

        $this->assertEquals($function['headers']['status-code'], 200);
        $this->assertCount(2, $function['body']['deployments']);

        $function = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/deployments', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('entrypoint', ['index.php'])->toString(),
            ],
        ]);

        $this->assertEquals($function['headers']['status-code'], 200);
        $this->assertCount(3, $function['body']['deployments']);

        $function = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/deployments', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('entrypoint', ['index.js'])->toString(),
            ],
        ]);

        $this->assertEquals($function['headers']['status-code'], 200);
        $this->assertCount(0, $function['body']['deployments']);

        $function = $this->client->call(
            Client::METHOD_GET,
            '/functions/' . $data['functionId'] . '/deployments',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders(), [
                'search' => 'Test'
            ])
        );

        $this->assertEquals($function['headers']['status-code'], 200);
        $this->assertEquals(3, $function['body']['total']);
        $this->assertIsArray($function['body']['deployments']);
        $this->assertCount(3, $function['body']['deployments']);
        $this->assertEquals($function['body']['deployments'][0]['$id'], $data['deploymentId']);

        $function = $this->client->call(
            Client::METHOD_GET,
            '/functions/' . $data['functionId'] . '/deployments',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders(), [
                'search' => 'php-8.0'
            ])
        );

        $this->assertEquals($function['headers']['status-code'], 200);
        $this->assertEquals(3, $function['body']['total']);
        $this->assertIsArray($function['body']['deployments']);
        $this->assertCount(3, $function['body']['deployments']);
        $this->assertEquals($function['body']['deployments'][0]['$id'], $data['deploymentId']);

        $function = $this->client->call(
            Client::METHOD_GET,
            '/functions/' . $data['functionId'] . '/deployments',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()),
            [
                'queries' => [
                    Query::equal('type', ['manual'])->toString(),
                ],
            ]
        );

        $this->assertEquals($function['headers']['status-code'], 200);
        $this->assertEquals(3, $function['body']['total']);

        $function = $this->client->call(
            Client::METHOD_GET,
            '/functions/' . $data['functionId'] . '/deployments',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()),
            [
                'queries' => [
                    Query::equal('type', ['vcs'])->toString(),
                ],
            ]
        );

        $this->assertEquals($function['headers']['status-code'], 200);
        $this->assertEquals(0, $function['body']['total']);

        $function = $this->client->call(
            Client::METHOD_GET,
            '/functions/' . $data['functionId'] . '/deployments',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()),
            [
                'queries' => [
                    Query::equal('type', ['invalid-string'])->toString(),
                ],
            ]
        );

        $this->assertEquals($function['headers']['status-code'], 200);
        $this->assertEquals(0, $function['body']['total']);

        $function = $this->client->call(
            Client::METHOD_GET,
            '/functions/' . $data['functionId'] . '/deployments',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()),
            [
                'queries' => [
                    Query::greaterThan('size', 10000)->toString(),
                ],
            ]
        );

        $this->assertEquals($function['headers']['status-code'], 200);
        $this->assertEquals(1, $function['body']['total']);

        $function = $this->client->call(
            Client::METHOD_GET,
            '/functions/' . $data['functionId'] . '/deployments',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()),
            [
                'queries' => [
                    Query::greaterThan('size', 0)->toString(),
                ],
            ]
        );

        $this->assertEquals($function['headers']['status-code'], 200);
        $this->assertEquals(3, $function['body']['total']);

        $function = $this->client->call(
            Client::METHOD_GET,
            '/functions/' . $data['functionId'] . '/deployments',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()),
            [
                'queries' => [
                    Query::greaterThan('size', -100)->toString(),
                ],
            ]
        );

        $this->assertEquals($function['headers']['status-code'], 200);
        $this->assertEquals(3, $function['body']['total']);

        /**
         * Ensure size output and size filters work exactly.
         * Prevents buildSize being counted towards deployemtn size
         */
        $response = $this->client->call(
            Client::METHOD_GET,
            '/functions/' . $data['functionId'] . '/deployments',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()),
            [
                Query::limit(1)->toString(),
            ]
        );

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $response['body']['total']);
        $this->assertNotEmpty($response['body']['deployments'][0]['$id']);
        $this->assertNotEmpty($response['body']['deployments'][0]['size']);

        $deploymentId = $function['body']['deployments'][0]['$id'];
        $deploymentSize = $function['body']['deployments'][0]['size'];

        $response = $this->client->call(
            Client::METHOD_GET,
            '/functions/' . $data['functionId'] . '/deployments',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()),
            [
                'queries' => [
                    Query::equal('size', [$deploymentSize])->toString(),
                ],
            ]
        );

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertGreaterThan(0, $response['body']['total']);

        $found = false;
        foreach ($response['body']['deployments'] as $deployment) {
            if($deployment['$id'] === $deploymentId) {
                $found = true;
                $this->assertEquals($deploymentSize, $deployment['size']);
                break;
            }
        }

        $this->assertTrue($found);

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
        $function = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/deployments/' . $data['deploymentId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $function['headers']['status-code']);
        $this->assertGreaterThan(0, $function['body']['buildTime']);
        $this->assertNotEmpty($function['body']['status']);
        $this->assertNotEmpty($function['body']['buildLogs']);
        $this->assertArrayHasKey('size', $function['body']);
        $this->assertArrayHasKey('buildSize', $function['body']);

        /**
         * Test for FAILURE
         */
        $function = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/deployments/x', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($function['headers']['status-code'], 404);

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
        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $data['functionId'] . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'async' => false,
        ]);

        $executionId = $execution['body']['$id'] ?? '';

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
        $this->assertEquals('', $execution['body']['errors']);
        $this->assertEquals('', $execution['body']['logs']);
        $this->assertLessThan(10, $execution['body']['duration']);

        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $data['functionId'] . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'async' => false,
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
        $function = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($function['headers']['status-code'], 200);
        $this->assertEquals($function['body']['total'], 1);
        $this->assertIsArray($function['body']['executions']);
        $this->assertCount(1, $function['body']['executions']);
        $this->assertEquals($function['body']['executions'][0]['$id'], $data['executionId']);

        $response = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::limit(1)->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(1, $response['body']['executions']);

        $response = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::offset(1)->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(0, $response['body']['executions']);

        $response = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('trigger', ['http'])->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(1, $response['body']['executions']);

        /**
         * Test search queries
         */

        $response = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => $data['executionId'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['total']);
        $this->assertIsInt($response['body']['total']);
        $this->assertCount(1, $response['body']['executions']);
        $this->assertEquals($data['functionId'], $response['body']['executions'][0]['functionId']);

        $response = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => $data['functionId'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['total']);
        $this->assertIsInt($response['body']['total']);
        $this->assertCount(1, $response['body']['executions']);
        $this->assertEquals($data['executionId'], $response['body']['executions'][0]['$id']);

        return $data;
    }

    /**
     * @depends testUpdateDeployment
     */
    #[Retry(count: 2)]
    public function testSyncCreateExecution($data): array
    {
        /**
         * Test for SUCCESS
         */
        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $data['functionId'] . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            // Testing default value, should be 'async' => false
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
        $function = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/executions/' . $data['executionId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($function['headers']['status-code'], 200);
        $this->assertEquals($function['body']['$id'], $data['executionId']);

        /**
         * Test for FAILURE
         */
        $function = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/executions/x', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

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
    public function testDeleteScheduledExecution($data): array
    {
        $futureTime = (new \DateTime())->add(new \DateInterval('PT10H'));
        $futureTime->setTime($futureTime->format('H'), $futureTime->format('i'), 0, 0);

        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $data['functionId'] . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'async' => true,
            'scheduledAt' => $futureTime->format('Y-m-d H:i:s'),
        ]);

        $executionId = $execution['body']['$id'] ?? '';
        $this->assertEquals(202, $execution['headers']['status-code']);
        sleep(5);
        $execution = $this->client->call(Client::METHOD_DELETE, '/functions/' . $data['functionId'] . '/executions/' . $executionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $execution['headers']['status-code']);
        $this->assertEmpty($execution['body']);

        return $data;
    }

    /**
     * @depends testGetExecution
     */
    #[Retry(count: 2)]
    public function testUpdateSpecs($data): array
    {
        /**
         * Test for SUCCESS
         */
        $response1 = $this->client->call(Client::METHOD_PUT, '/functions/' . $data['functionId'], array_merge([
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

        $this->assertEquals(200, $response1['headers']['status-code']);
        $this->assertNotEmpty($response1['body']['$id']);
        $this->assertEquals(Specification::S_1VCPU_1GB, $response1['body']['specification']);

        // Test Execution
        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $data['functionId'] . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $output = json_decode($execution['body']['responseBody'], true);

        $this->assertEquals(1, $output['APPWRITE_FUNCTION_CPUS']);
        $this->assertEquals(1024, $output['APPWRITE_FUNCTION_MEMORY']);

        $response2 = $this->client->call(Client::METHOD_PUT, '/functions/' . $data['functionId'], array_merge([
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

        $this->assertEquals(200, $response2['headers']['status-code']);
        $this->assertNotEmpty($response2['body']['$id']);
        $this->assertEquals(Specification::S_1VCPU_512MB, $response2['body']['specification']);

        // Test Execution
        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $data['functionId'] . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $output = json_decode($execution['body']['responseBody'], true);

        $this->assertEquals(1, $output['APPWRITE_FUNCTION_CPUS']);
        $this->assertEquals(512, $output['APPWRITE_FUNCTION_MEMORY']);

        /**
         * Test for FAILURE
         */
        $response3 = $this->client->call(Client::METHOD_PUT, '/functions/' . $data['functionId'], array_merge([
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

        $this->assertEquals(400, $response3['headers']['status-code']);
        $this->assertStringStartsWith('Invalid `specification` param: Specification must be one of:', $response3['body']['message']);

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
        $function = $this->client->call(Client::METHOD_DELETE, '/functions/' . $data['functionId'] . '/deployments/' . $data['deploymentId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $function['headers']['status-code']);
        $this->assertEmpty($function['body']);

        $function = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/deployments/' . $data['deploymentId'], array_merge([
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
     * @depends testCreateDeployment
     */
    public function testDelete($data): array
    {
        /**
         * Test for SUCCESS
         */
        $function = $this->client->call(Client::METHOD_DELETE, '/functions/' . $data['functionId'], array_merge([
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
        $entrypoint = 'index.php';
        $timeout = 15;
        $folder = 'timeout';
        $code = realpath(__DIR__ . '/../../../resources/functions') . "/$folder/code.tar.gz";
        $this->packageCode($folder);

        $function = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'functionId' => ID::unique(),
            'name' => 'Test ' . $name,
            'runtime' => $name,
            'entrypoint' => $entrypoint,
            'events' => [],
            'schedule' => '* * * * *', // execute every minute
            'timeout' => $timeout,
        ]);

        $functionId = $function['body']['$id'] ?? '';

        $this->assertEquals(201, $function['headers']['status-code']);
        $this->assertEquals('* * * * *', $function['body']['schedule']);

        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/deployments', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'entrypoint' => $entrypoint,
            'code' => new CURLFile($code, 'application/x-gzip', basename($code)),
            'activate' => true,
        ]);

        $deploymentId = $deployment['body']['$id'] ?? '';

        $this->assertEquals(202, $deployment['headers']['status-code']);

        $this->awaitDeploymentIsBuilt($functionId, $deploymentId);

        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'async' => true,
        ]);

        $executionId = $execution['body']['$id'] ?? '';

        $this->assertEquals(202, $execution['headers']['status-code']);

        sleep(20);

        $executions = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('trigger', ['http'])->toString(),
            ],
        ]);

        $this->assertEquals($executions['headers']['status-code'], 200);
        $this->assertEquals($executions['body']['total'], 1);
        $this->assertIsArray($executions['body']['executions']);
        $this->assertCount(1, $executions['body']['executions']);
        $this->assertEquals($executions['body']['executions'][0]['$id'], $executionId);
        $this->assertEquals($executions['body']['executions'][0]['trigger'], 'http');
        $this->assertEquals($executions['body']['executions'][0]['status'], 'failed');
        $this->assertEquals($executions['body']['executions'][0]['responseStatusCode'], 500);
        $this->assertGreaterThan(2, $executions['body']['executions'][0]['duration']);
        $this->assertLessThan(20, $executions['body']['executions'][0]['duration']);
        $this->assertEquals($executions['body']['executions'][0]['responseBody'], '');
        $this->assertEquals($executions['body']['executions'][0]['logs'], '');
        $this->assertStringContainsString('timed out', $executions['body']['executions'][0]['errors']);

        $start = \microtime(true);
        while (true) {
            $executions = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/executions', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'queries' => [
                    Query::equal('trigger', ['schedule'])->toString(),
                ],
            ]);

            $this->assertEquals(200, $executions['headers']['status-code']);

            if (\count($executions['body']['executions']) > 0) {
                break;
            }

            // 0s would mean instant execution
            // +60 seconds, maximum possible waiting time before next minute
            // +10 seconds, maximum update interval time
            // +60 seconds, possible overlap between update and schedule tick
            // +10 seconds, maximum execution time including cold-start
            // Result: We allow maximum
            if (\microtime(true) - $start > 140) {
                $this->fail('Execution did not create within 140 seconds of schedule: ' . \json_encode($executions));
            }

            usleep(1000000); // 1 second
        }

        $this->assertEquals(200, $executions['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, \count($executions['body']['executions']));
        $this->assertEquals($executions['body']['executions'][0]['trigger'], 'schedule');

        // Cleanup : Delete function
        $response = $this->client->call(Client::METHOD_DELETE, '/functions/' . $functionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], []);

        $this->assertEquals(204, $response['headers']['status-code']);
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
     * @depends      testTimeout
     */
    public function testCreateCustomExecution(string $folder, string $name, string $entrypoint, string $runtimeName, string $runtimeVersion)
    {
        $timeout = 15;
        $code = realpath(__DIR__ . '/../../../resources/functions') . "/$folder/code.tar.gz";
        $this->packageCode($folder);

        $function = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'functionId' => ID::unique(),
            'name' => 'Test ' . $name,
            'runtime' => $name,
            'entrypoint' => $entrypoint,
            'events' => [],
            'timeout' => $timeout,
        ]);

        $functionId = $function['body']['$id'] ?? '';

        $this->assertEquals(201, $function['headers']['status-code']);

        $variable = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => 'CUSTOM_VARIABLE',
            'value' => 'variable',
        ]);

        $this->assertEquals(201, $variable['headers']['status-code']);

        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/deployments', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'entrypoint' => $entrypoint,
            'code' => new CURLFile($code, 'application/x-gzip', basename($code)),
            'activate' => true
        ]);

        $deploymentId = $deployment['body']['$id'] ?? '';
        $this->assertEquals(202, $deployment['headers']['status-code']);

        $this->awaitDeploymentIsBuilt($function['body']['$id'], $deploymentId, checkForSuccess: false);

        $deployment = $this->client->call(Client::METHOD_PATCH, '/functions/' . $functionId . '/deployments/' . $deploymentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $deployment['headers']['status-code']);

        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'body' => 'foobar',
            'async' => false
        ]);

        $executionId = $execution['body']['$id'] ?? '';
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

        $executions = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($executions['headers']['status-code'], 200);
        $this->assertEquals($executions['body']['total'], 1);
        $this->assertIsArray($executions['body']['executions']);
        $this->assertCount(1, $executions['body']['executions']);
        $this->assertEquals($executions['body']['executions'][0]['$id'], $executionId);
        $this->assertEquals($executions['body']['executions'][0]['trigger'], 'http');
        $this->assertStringContainsString('Amazing Function Log', $executions['body']['executions'][0]['logs']);

        // Cleanup : Delete function
        $response = $this->client->call(Client::METHOD_DELETE, '/functions/' . $functionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], []);

        $this->assertEquals(204, $response['headers']['status-code']);
    }

    public function testCreateCustomExecutionBinaryResponse()
    {
        $timeout = 15;
        $code = realpath(__DIR__ . '/../../../resources/functions') . "/php-binary-response/code.tar.gz";
        $this->packageCode('php-binary-response');

        $function = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'functionId' => ID::unique(),
            'name' => 'Test PHP Binary executions',
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'timeout' => $timeout,
            'execute' => ['any']
        ]);

        $functionId = $function['body']['$id'] ?? '';

        $this->assertEquals(201, $function['headers']['status-code']);

        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/deployments', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'entrypoint' => 'index.php',
            'code' => new CURLFile($code, 'application/x-gzip', basename($code)),
            'activate' => true
        ]);

        $deploymentId = $deployment['body']['$id'] ?? '';
        $this->assertEquals(202, $deployment['headers']['status-code']);

        $this->awaitDeploymentIsBuilt($function['body']['$id'], $deploymentId, checkForSuccess: false);

        $deployment = $this->client->call(Client::METHOD_PATCH, '/functions/' . $functionId . '/deployments/' . $deploymentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $deployment['headers']['status-code']);

        // Wait a little for activation to finish
        sleep(5);

        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/executions', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
            'accept' => 'multipart/form-data',
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
            'accept' => 'application/json',
        ], $this->getHeaders()), [
            'body' => null,
        ]);

        $this->assertEquals(400, $execution['headers']['status-code']);
        $this->assertStringContainsString('Failed to parse response', $execution['body']['message']);

        // Cleanup : Delete function
        $response = $this->client->call(Client::METHOD_DELETE, '/functions/' . $functionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], []);

        $this->assertEquals(204, $response['headers']['status-code']);
    }

    public function testCreateCustomExecutionBinaryRequest()
    {
        $timeout = 15;
        $code = realpath(__DIR__ . '/../../../resources/functions') . "/php-binary-request/code.tar.gz";
        $this->packageCode('php-binary-request');

        $function = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'functionId' => ID::unique(),
            'name' => 'Test PHP Binary executions',
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'timeout' => $timeout,
            'execute' => ['any']
        ]);

        $functionId = $function['body']['$id'] ?? '';

        $this->assertEquals(201, $function['headers']['status-code']);

        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/deployments', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'entrypoint' => 'index.php',
            'code' => new CURLFile($code, 'application/x-gzip', basename($code)),
            'activate' => true
        ]);

        $deploymentId = $deployment['body']['$id'] ?? '';
        $this->assertEquals(202, $deployment['headers']['status-code']);

        $this->awaitDeploymentIsBuilt($function['body']['$id'], $deploymentId, checkForSuccess: false);

        $deployment = $this->client->call(Client::METHOD_PATCH, '/functions/' . $functionId . '/deployments/' . $deploymentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $deployment['headers']['status-code']);

        // Wait a little for activation to finish
        sleep(5);

        $bytes = pack('C*', ...[0, 20, 255]);

        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/executions', array_merge([
            'content-type' => 'multipart/form-data',
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
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'accept' => 'application/json',
        ], $this->getHeaders()), [
            'body' => $bytes,
        ], false);

        $executionBody = json_decode($execution['body'], true);
        $this->assertNotEquals(\md5($bytes), $executionBody['responseBody']);

        // Cleanup : Delete function
        $response = $this->client->call(Client::METHOD_DELETE, '/functions/' . $functionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], []);

        $this->assertEquals(204, $response['headers']['status-code']);
    }

    public function testv2Function()
    {
        $timeout = 15;
        $code = realpath(__DIR__ . '/../../../resources/functions') . "/php-v2/code.tar.gz";
        $this->packageCode('php-v2');

        $function = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'functionId' => ID::unique(),
            'name' => 'Test PHP V2',
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'events' => [],
            'timeout' => $timeout,
        ]);

        $functionId = $function['body']['$id'] ?? '';

        $this->assertEquals(201, $function['headers']['status-code']);

        $headers = [
            'content-type' => 'application/json',
            'origin' => 'http://localhost',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-mode' => 'admin',
        ];

        $variable = $this->client->call(Client::METHOD_PATCH, '/mock/functions-v2', $headers, [
            'functionId' => $functionId
        ]);

        $this->assertEquals(204, $variable['headers']['status-code']);

        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/deployments', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'entrypoint' => 'index.php',
            'code' => new CURLFile($code, 'application/x-gzip', basename($code)),
            'activate' => true
        ]);

        $deploymentId = $deployment['body']['$id'] ?? '';
        $this->assertEquals(202, $deployment['headers']['status-code']);

        $this->awaitDeploymentIsBuilt($function['body']['$id'], $deploymentId, checkForSuccess: false);

        $deployment = $this->client->call(Client::METHOD_PATCH, '/functions/' . $functionId . '/deployments/' . $deploymentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $deployment['headers']['status-code']);

        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'body' => 'foobar',
            'async' => false
        ]);

        $output = json_decode($execution['body']['responseBody'], true);

        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertEquals('completed', $execution['body']['status']);
        $this->assertEquals(200, $execution['body']['responseStatusCode']);
        $this->assertEquals(true, $output['v2Woks']);

        // Cleanup : Delete function
        $response = $this->client->call(Client::METHOD_DELETE, '/functions/' . $functionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], []);

        $this->assertEquals(204, $response['headers']['status-code']);
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
        $timeout = 15;
        $code = realpath(__DIR__ . '/../../../resources/functions') . "/php-event/code.tar.gz";
        $this->packageCode('php-event');

        $function = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'functionId' => ID::unique(),
            'name' => 'Test PHP Event executions',
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'events' => [
                'users.*.create',
            ],
            'timeout' => $timeout,
        ]);

        $functionId = $function['body']['$id'] ?? '';

        $this->assertEquals(201, $function['headers']['status-code']);

        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/deployments', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'entrypoint' => 'index.php',
            'code' => new CURLFile($code, 'application/x-gzip', basename($code)),
            'activate' => true
        ]);

        $deploymentId = $deployment['body']['$id'] ?? '';
        $this->assertEquals(202, $deployment['headers']['status-code']);

        $this->awaitDeploymentIsBuilt($function['body']['$id'], $deploymentId, checkForSuccess: false);

        $deployment = $this->client->call(Client::METHOD_PATCH, '/functions/' . $functionId . '/deployments/' . $deploymentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $deployment['headers']['status-code']);

        // Wait a little for activation to finish
        sleep(5);

        // Create user to trigger event
        $user = $this->client->call(Client::METHOD_POST, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => 'unique()',
            'name' => 'Event User'
        ]);

        $userId = $user['body']['$id'];

        $this->assertEquals(201, $user['headers']['status-code']);

        // Wait for execution to occur
        sleep(15);

        $executions = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $execution = $executions['body']['executions'][0];

        $this->assertEquals(200, $executions['headers']['status-code']);
        $this->assertEquals('completed', $execution['status']);
        $this->assertEquals(204, $execution['responseStatusCode']);
        $this->assertStringContainsString($userId, $execution['logs']);
        $this->assertStringContainsString('Event User', $execution['logs']);

        // Cleanup : Delete function
        $response = $this->client->call(Client::METHOD_DELETE, '/functions/' . $functionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], []);

        $this->assertEquals(204, $response['headers']['status-code']);

        // Cleanup : Delete user
        $response = $this->client->call(Client::METHOD_DELETE, '/users/' . $userId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], []);

        $this->assertEquals(204, $response['headers']['status-code']);
    }

    public function testScopes()
    {
        $timeout = 15;
        $code = realpath(__DIR__ . '/../../../resources/functions') . "/php-scopes/code.tar.gz";
        $this->packageCode('php-scopes');

        $function = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'functionId' => ID::unique(),
            'name' => 'Test PHP Scopes executions',
            'commands' => 'composer update --no-interaction --ignore-platform-reqs --optimize-autoloader --prefer-dist --no-dev',
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'scopes' => ['users.read'],
            'timeout' => $timeout,
        ]);

        $functionId = $function['body']['$id'] ?? '';

        $this->assertEquals(201, $function['headers']['status-code']);

        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/deployments', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'entrypoint' => 'index.php',
            'commands' => 'sh setup.sh && composer install',
            'code' => new CURLFile($code, 'application/x-gzip', basename($code)),
            'activate' => true
        ]);

        $deploymentId = $deployment['body']['$id'] ?? '';
        $this->assertEquals(202, $deployment['headers']['status-code']);

        $this->awaitDeploymentIsBuilt($function['body']['$id'], $deploymentId, checkForSuccess: false);

        $deployment = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/deployments/' . $deploymentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $deployment['headers']['status-code']);
        $this->assertStringContainsStringIgnoringCase("200 OK", $deployment['body']['buildLogs']);
        $this->assertStringContainsStringIgnoringCase('"total":', $deployment['body']['buildLogs']);
        $this->assertStringContainsStringIgnoringCase('"users":', $deployment['body']['buildLogs']);

        $deployment = $this->client->call(Client::METHOD_PATCH, '/functions/' . $functionId . '/deployments/' . $deploymentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $deployment['headers']['status-code']);

        // Wait a little for activation to finish
        sleep(5);

        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'async' => false
        ]);

        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertEquals('completed', $execution['body']['status']);
        $this->assertEquals(200, $execution['body']['responseStatusCode']);
        $this->assertGreaterThan(0, $execution['body']['duration']);
        $this->assertNotEmpty($execution['body']['responseBody']);
        $this->assertStringContainsString("total", $execution['body']['responseBody']);

        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'async' => true
        ]);

        $this->assertEquals(202, $execution['headers']['status-code']);
        $this->assertNotEmpty($execution['body']['$id']);

        \sleep(10);

        $execution = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/executions/' . $execution['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $execution['headers']['status-code']);
        $this->assertEquals('completed', $execution['body']['status']);
        $this->assertEquals(200, $execution['body']['responseStatusCode']);
        $this->assertGreaterThan(0, $execution['body']['duration']);
        $this->assertNotEmpty($execution['body']['logs']);
        $this->assertStringContainsString("total", $execution['body']['logs']);

        // Cleanup : Delete function
        $response = $this->client->call(Client::METHOD_DELETE, '/functions/' . $functionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], []);

        $this->assertEquals(204, $response['headers']['status-code']);
    }

    public function testCookieExecution()
    {
        $timeout = 15;
        $code = realpath(__DIR__ . '/../../../resources/functions') . "/php-cookie/code.tar.gz";
        $this->packageCode('php-cookie');

        $function = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'functionId' => ID::unique(),
            'name' => 'Test PHP Cookie executions',
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'timeout' => $timeout,
        ]);

        $functionId = $function['body']['$id'] ?? '';

        $this->assertEquals(201, $function['headers']['status-code']);

        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/deployments', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'entrypoint' => 'index.php',
            'code' => new CURLFile($code, 'application/x-gzip', basename($code)),
            'activate' => true
        ]);

        $deploymentId = $deployment['body']['$id'] ?? '';
        $this->assertEquals(202, $deployment['headers']['status-code']);

        $this->awaitDeploymentIsBuilt($function['body']['$id'], $deploymentId, checkForSuccess: false);

        $deployment = $this->client->call(Client::METHOD_PATCH, '/functions/' . $functionId . '/deployments/' . $deploymentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $deployment['headers']['status-code']);

        // Wait a little for activation to finish
        sleep(5);

        $cookie = 'cookieName=cookieValue; cookie2=value2; cookie3=value=3; cookie4=val:ue4; cookie5=value5';

        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'async' => false,
            'headers' => [
                'cookie' => $cookie
            ]
        ]);

        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertEquals('completed', $execution['body']['status']);
        $this->assertEquals(200, $execution['body']['responseStatusCode']);
        $this->assertEquals($cookie, $execution['body']['responseBody']);
        $this->assertGreaterThan(0, $execution['body']['duration']);

        // Cleanup : Delete function
        $response = $this->client->call(Client::METHOD_DELETE, '/functions/' . $functionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], []);

        $this->assertEquals(204, $response['headers']['status-code']);
    }

    public function testFunctionsDomain()
    {
        $timeout = 15;
        $code = realpath(__DIR__ . '/../../../resources/functions') . "/php-cookie/code.tar.gz";
        $this->packageCode('php-cookie');

        $function = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'functionId' => ID::unique(),
            'name' => 'Test PHP Cookie executions',
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'timeout' => $timeout,
            'execute' => ['any']
        ]);

        $functionId = $function['body']['$id'] ?? '';

        $this->assertEquals(201, $function['headers']['status-code']);

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

        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/deployments', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'entrypoint' => 'index.php',
            'code' => new CURLFile($code, 'application/x-gzip', basename($code)),
            'activate' => true
        ]);

        $deploymentId = $deployment['body']['$id'] ?? '';
        $this->assertEquals(202, $deployment['headers']['status-code']);

        $this->awaitDeploymentIsBuilt($function['body']['$id'], $deploymentId, checkForSuccess: false);

        $deployment = $this->client->call(Client::METHOD_PATCH, '/functions/' . $functionId . '/deployments/' . $deploymentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $deployment['headers']['status-code']);

        // Wait a little for activation to finish
        sleep(5);

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

        // Await Aggregation
        sleep(App::getEnv('_APP_USAGE_AGGREGATION_INTERVAL', 30));

        $response = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '24h'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(19, count($response['body']));
        $this->assertEquals('24h', $response['body']['range']);
        $this->assertEquals(1, $response['body']['executionsTotal']);

        // Cleanup : Delete function
        $response = $this->client->call(Client::METHOD_DELETE, '/functions/' . $functionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], []);

        $this->assertEquals(204, $response['headers']['status-code']);
    }

    public function testFunctionsDomainBinaryResponse()
    {
        $timeout = 15;
        $code = realpath(__DIR__ . '/../../../resources/functions') . "/php-binary-response/code.tar.gz";
        $this->packageCode('php-binary-response');

        $function = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'functionId' => ID::unique(),
            'name' => 'Test PHP Binary executions',
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'timeout' => $timeout,
            'execute' => ['any']
        ]);

        $functionId = $function['body']['$id'] ?? '';

        $this->assertEquals(201, $function['headers']['status-code']);

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

        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/deployments', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'entrypoint' => 'index.php',
            'code' => new CURLFile($code, 'application/x-gzip', basename($code)),
            'activate' => true
        ]);

        $deploymentId = $deployment['body']['$id'] ?? '';
        $this->assertEquals(202, $deployment['headers']['status-code']);

        $this->awaitDeploymentIsBuilt($function['body']['$id'], $deploymentId, checkForSuccess: false);

        $deployment = $this->client->call(Client::METHOD_PATCH, '/functions/' . $functionId . '/deployments/' . $deploymentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $deployment['headers']['status-code']);

        // Wait a little for activation to finish
        sleep(5);

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

        // Cleanup : Delete function
        $response = $this->client->call(Client::METHOD_DELETE, '/functions/' . $functionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], []);

        $this->assertEquals(204, $response['headers']['status-code']);
    }

    public function testFunctionsDomainBinaryRequest()
    {
        $timeout = 15;
        $code = realpath(__DIR__ . '/../../../resources/functions') . "/php-binary-request/code.tar.gz";
        $this->packageCode('php-binary-request');

        $function = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'functionId' => ID::unique(),
            'name' => 'Test PHP Binary executions',
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'timeout' => $timeout,
            'execute' => ['any']
        ]);

        $functionId = $function['body']['$id'] ?? '';

        $this->assertEquals(201, $function['headers']['status-code']);

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

        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/deployments', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'entrypoint' => 'index.php',
            'code' => new CURLFile($code, 'application/x-gzip', basename($code)),
            'activate' => true
        ]);

        $deploymentId = $deployment['body']['$id'] ?? '';
        $this->assertEquals(202, $deployment['headers']['status-code']);

        $this->awaitDeploymentIsBuilt($function['body']['$id'], $deploymentId, checkForSuccess: false);

        $deployment = $this->client->call(Client::METHOD_PATCH, '/functions/' . $functionId . '/deployments/' . $deploymentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $deployment['headers']['status-code']);

        // Wait a little for activation to finish
        sleep(5);

        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $domain);

        $bytes = pack('C*', ...[0, 20, 255]);

        $response = $proxyClient->call(Client::METHOD_POST, '/', ['content-type' => 'text/plain'], $bytes, false);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(\md5($bytes), $response['body']);

        // Cleanup : Delete function
        $response = $this->client->call(Client::METHOD_DELETE, '/functions/' . $functionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], []);

        $this->assertEquals(204, $response['headers']['status-code']);
    }

    public function testCreateFunctionWithResponseFormatHeader()
    {
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

        // Cleanup : Delete function
        $response = $this->client->call(Client::METHOD_DELETE, '/functions/' . $response['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], []);

        $this->assertEquals(204, $response['headers']['status-code']);
    }
}
