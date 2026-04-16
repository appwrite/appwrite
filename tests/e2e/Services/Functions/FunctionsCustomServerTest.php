<?php

namespace Tests\E2E\Services\Functions;

use Appwrite\Platform\Modules\Compute\Specification;
use Appwrite\Tests\Retry;
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

class FunctionsCustomServerTest extends Scope
{
    use FunctionsBase;
    use ProjectCustom;
    use SideServer;

    public function testListSpecs(): void
    {
        $specifications = $this->listSpecifications();
        $this->assertEquals(200, $specifications['headers']['status-code']);
        $this->assertGreaterThan(0, $specifications['body']['total']);
        $this->assertArrayHasKey(0, $specifications['body']['specifications']);
        $this->assertArrayHasKey('memory', $specifications['body']['specifications'][0]);
        $this->assertArrayHasKey('cpus', $specifications['body']['specifications'][0]);
        $this->assertArrayHasKey('enabled', $specifications['body']['specifications'][0]);
        $this->assertArrayHasKey('slug', $specifications['body']['specifications'][0]);

        $function = $this->createFunction([
            'functionId' => ID::unique(),
            'name' => 'Specs function',
            'runtime' => 'node-22',
            'specification' => $specifications['body']['specifications'][0]['slug']
        ]);
        $this->assertEquals(201, $function['headers']['status-code']);
        $this->assertEquals($specifications['body']['specifications'][0]['slug'], $function['body']['specification']);

        $function = $this->getFunction($function['body']['$id']);
        $this->assertEquals(200, $function['headers']['status-code']);
        $this->assertEquals($specifications['body']['specifications'][0]['slug'], $function['body']['specification']);

        $this->cleanupFunction($function['body']['$id']);

        $function = $this->createFunction([
            'functionId' => ID::unique(),
            'name' => 'Specs function',
            'runtime' => 'node-22',
            'specification' => 'cheap-please'
        ]);
        $this->assertEquals(400, $function['headers']['status-code']);
    }

    public function testCreateFunction(): array
    {
        /**
         * Test for SUCCESS
         */
        $function = $this->createFunction([
            'functionId' => ID::unique(),
            'name' => 'Test',
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
            'events' => [
                'buckets.*.create',
                'buckets.*.delete',
            ],
            'timeout' => 10,
        ]);

        $functionId = $function['body']['$id'] ?? '';

        $dateValidator = new DatetimeValidator();
        $this->assertEquals(201, $function['headers']['status-code']);
        $this->assertNotEmpty($function['body']['$id']);
        $this->assertEquals('Test', $function['body']['name']);
        $this->assertEquals('node-22', $function['body']['runtime']);
        $this->assertEquals(true, $dateValidator->isValid($function['body']['$createdAt']));
        $this->assertEquals(true, $dateValidator->isValid($function['body']['$updatedAt']));
        $this->assertEquals('', $function['body']['deploymentId']);
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
            'search' => 'node-22'
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
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
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
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
        ]);

        $dateValidator = new DatetimeValidator();

        $this->assertEquals(200, $function['headers']['status-code']);
        $this->assertNotEmpty($function['body']['$id']);
        $this->assertEquals('Test1', $function['body']['name']);
        $this->assertEquals(true, $dateValidator->isValid($function['body']['$createdAt']));
        $this->assertEquals(true, $dateValidator->isValid($function['body']['$updatedAt']));
        $this->assertEquals('', $function['body']['deploymentId']);
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
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
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
            'code' => $this->packageFunction('basic'),
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

        $runtime = array_values(array_filter($starterTemplate['body']['runtimes'], function ($runtime) {
            return $runtime['name'] === 'node-22';
        }))[0];

        // If this fails, the template has variables, and this test needs to be updated
        $this->assertEmpty($starterTemplate['body']['variables']);

        $function = $this->createFunction(
            [
                'functionId' => ID::unique(),
                'name' => $starterTemplate['body']['name'],
                'runtime' => 'node-22',
                'execute' => $starterTemplate['body']['permissions'],
                'entrypoint' => $runtime['entrypoint'],
                'events' => $starterTemplate['body']['events'],
                'schedule' => $starterTemplate['body']['cron'],
                'timeout' => $starterTemplate['body']['timeout'],
                'commands' => $runtime['commands'],
                'scopes' => $starterTemplate['body']['scopes'],
                'templateRepository' => $starterTemplate['body']['providerRepositoryId'],
                'templateOwner' => $starterTemplate['body']['providerOwner'],
                'templateRootDirectory' => $runtime['providerRootDirectory'],
                'templateVersion' => $starterTemplate['body']['providerVersion'],
            ]
        );

        $this->assertEquals(201, $function['headers']['status-code']);
        $this->assertNotEmpty($function['body']['$id']);

        $functionId = $function['body']['$id'] ?? '';

        $deployment = $this->createTemplateDeployment(
            $functionId,
            [
                'resourceId' => ID::unique(),
                'activate' => true,
                'repository' => $starterTemplate['body']['providerRepositoryId'],
                'owner' => $starterTemplate['body']['providerOwner'],
                'rootDirectory' => $runtime['providerRootDirectory'],
                'type' => 'tag',
                'reference' => $starterTemplate['body']['providerVersion'],
            ]
        );

        $this->assertEquals(202, $deployment['headers']['status-code']);
        $this->assertNotEmpty($deployment['body']['$id']);

        // Wait for deployment to be ready
        $deploymentId = $deployment['body']['$id'];
        $this->assertEventually(function () use ($functionId, $deploymentId) {
            $deployment = $this->getDeployment($functionId, $deploymentId);
            $this->assertEquals('ready', $deployment['body']['status']);
        }, 50000, 500);

        // Verify deployment sizes
        $deployment = $this->getDeployment($functionId, $deploymentId);
        $this->assertEquals(200, $deployment['headers']['status-code']);
        $this->assertGreaterThan(0, $deployment['body']['sourceSize']);
        $this->assertGreaterThan(0, $deployment['body']['buildSize']);
        $totalSize = $deployment['body']['sourceSize'] + $deployment['body']['buildSize'];
        $this->assertEquals($totalSize, $deployment['body']['totalSize']);

        $deployments = $this->listDeployments($functionId);

        $this->assertEquals(200, $deployments['headers']['status-code']);
        $this->assertEquals(1, $deployments['body']['total']);

        /**
         * Test for SUCCESS with total=false
         */
        $deploymentsWithIncludeTotalFalse = $this->listDeployments($functionId, ['total' => false]);

        $this->assertEquals(200, $deploymentsWithIncludeTotalFalse['headers']['status-code']);
        $this->assertIsArray($deploymentsWithIncludeTotalFalse['body']);
        $this->assertIsArray($deploymentsWithIncludeTotalFalse['body']['deployments']);
        $this->assertIsInt($deploymentsWithIncludeTotalFalse['body']['total']);
        $this->assertEquals(0, $deploymentsWithIncludeTotalFalse['body']['total']);
        $this->assertGreaterThan(0, count($deploymentsWithIncludeTotalFalse['body']['deployments']));

        $lastDeployment = $deployments['body']['deployments'][0];

        $this->assertNotEmpty($lastDeployment['$id']);
        $this->assertGreaterThan(0, $lastDeployment['sourceSize']);

        $function = $this->getFunction($functionId);

        $this->assertEquals(200, $function['headers']['status-code']);
        $this->assertEquals($deploymentId, $function['body']['deploymentId']);

        // Test starter code is used and that dynamic keys work
        $execution = $this->createExecution($functionId, [
            'path' => '/ping',
        ]);

        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertEquals("completed", $execution['body']['status']);
        $this->assertEquals(200, $execution['body']['responseStatusCode']);
        $this->assertEquals("Pong", $execution['body']['responseBody']);
        $this->assertEmpty($execution['body']['errors'], 'Failed to execute function, ' . json_encode($execution['body']['errors']));

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

        $deployment = $this->getDeployment($functionId, $deployment['body']['$id']);
        $this->assertEquals(200, $deployment['headers']['status-code']);
        $this->assertGreaterThan(0, $deployment['body']['sourceSize']);
        $this->assertGreaterThan(0, $deployment['body']['buildSize']);
        $totalSize = $deployment['body']['sourceSize'] + $deployment['body']['buildSize'];
        $this->assertEquals($totalSize, $deployment['body']['totalSize']);

        $function = $this->getFunction($functionId);
        $this->assertEquals(200, $function['headers']['status-code']);
        $this->assertNotEmpty($function['body']['deploymentId']);
        $this->assertNotEmpty($function['body']['deploymentCreatedAt']);
        $this->assertEquals($deployment['body']['$id'], $function['body']['deploymentId']);
        $this->assertEquals($deployment['body']['$createdAt'], $function['body']['deploymentCreatedAt']);

        $this->cleanupFunction($functionId);
    }

    public function testCreateFunctionAndDeploymentFromTemplateBranch()
    {
        $starterTemplate = $this->getTemplate('starter');
        $this->assertEquals(200, $starterTemplate['headers']['status-code']);

        $runtime = array_values(array_filter($starterTemplate['body']['runtimes'], function ($runtime) {
            return $runtime['name'] === 'node-22';
        }))[0];

        // If this fails, the template has variables, and this test needs to be updated
        $this->assertEmpty($starterTemplate['body']['variables']);

        $function = $this->createFunction(
            [
                'functionId' => ID::unique(),
                'name' => $starterTemplate['body']['name'] . ' - Branch Test',
                'runtime' => 'node-22',
                'execute' => $starterTemplate['body']['permissions'],
                'entrypoint' => $runtime['entrypoint'],
                'events' => $starterTemplate['body']['events'],
                'schedule' => $starterTemplate['body']['cron'],
                'timeout' => $starterTemplate['body']['timeout'],
                'commands' => $runtime['commands'],
                'scopes' => $starterTemplate['body']['scopes'],
            ]
        );

        $this->assertEquals(201, $function['headers']['status-code']);
        $this->assertNotEmpty($function['body']['$id']);

        $functionId = $function['body']['$id'] ?? '';

        // Deploy using branch
        $deployment = $this->createTemplateDeployment(
            $functionId,
            [
                'resourceId' => ID::unique(),
                'activate' => true,
                'repository' => $starterTemplate['body']['providerRepositoryId'],
                'owner' => $starterTemplate['body']['providerOwner'],
                'rootDirectory' => $runtime['providerRootDirectory'],
                'type' => 'branch',
                'reference' => 'main',
            ]
        );

        $this->assertEquals(202, $deployment['headers']['status-code']);
        $this->assertNotEmpty($deployment['body']['$id']);

        $deploymentId = $deployment['body']['$id'];
        $this->assertEventually(function () use ($functionId, $deploymentId) {
            $deployment = $this->getDeployment($functionId, $deploymentId);
            $this->assertEquals('ready', $deployment['body']['status']);
        }, 50000, 500);

        $deployment = $this->getDeployment($functionId, $deploymentId);
        $this->assertEquals(200, $deployment['headers']['status-code']);
        $this->assertGreaterThan(0, $deployment['body']['sourceSize']);
        $this->assertGreaterThan(0, $deployment['body']['buildSize']);
        $totalSize = $deployment['body']['sourceSize'] + $deployment['body']['buildSize'];
        $this->assertEquals($totalSize, $deployment['body']['totalSize']);

        $this->cleanupFunction($functionId);
    }

    public function testCreateFunctionAndDeploymentFromTemplateCommit()
    {
        $starterTemplate = $this->getTemplate('starter');
        $this->assertEquals(200, $starterTemplate['headers']['status-code']);

        // Get latest commit using helper function
        $latestCommit = $this->helperGetLatestCommit(
            $starterTemplate['body']['providerOwner'],
            $starterTemplate['body']['providerRepositoryId']
        );
        $this->assertNotNull($latestCommit);

        $runtime = array_values(array_filter($starterTemplate['body']['runtimes'], function ($runtime) {
            return $runtime['name'] === 'node-22';
        }))[0];

        // If this fails, the template has variables, and this test needs to be updated
        $this->assertEmpty($starterTemplate['body']['variables']);

        $function = $this->createFunction(
            [
                'functionId' => ID::unique(),
                'name' => $starterTemplate['body']['name'] . ' - Commit Test',
                'runtime' => 'node-22',
                'execute' => $starterTemplate['body']['permissions'],
                'entrypoint' => $runtime['entrypoint'],
                'events' => $starterTemplate['body']['events'],
                'schedule' => $starterTemplate['body']['cron'],
                'timeout' => $starterTemplate['body']['timeout'],
                'commands' => $runtime['commands'],
                'scopes' => $starterTemplate['body']['scopes'],
            ]
        );

        $this->assertEquals(201, $function['headers']['status-code']);
        $this->assertNotEmpty($function['body']['$id']);

        $functionId = $function['body']['$id'] ?? '';

        // Deploy using commit
        $deployment = $this->createTemplateDeployment(
            $functionId,
            [
                'resourceId' => ID::unique(),
                'activate' => true,
                'repository' => $starterTemplate['body']['providerRepositoryId'],
                'owner' => $starterTemplate['body']['providerOwner'],
                'rootDirectory' => $runtime['providerRootDirectory'],
                'type' => 'commit',
                'reference' => $latestCommit,
            ]
        );

        $this->assertEquals(202, $deployment['headers']['status-code']);
        $this->assertNotEmpty($deployment['body']['$id']);

        $deploymentId = $deployment['body']['$id'];
        $this->assertEventually(function () use ($functionId, $deploymentId) {
            $deployment = $this->getDeployment($functionId, $deploymentId);
            $this->assertEquals('ready', $deployment['body']['status']);
        }, 50000, 500);

        $deployment = $this->getDeployment($functionId, $deploymentId);
        $this->assertEquals(200, $deployment['headers']['status-code']);
        $this->assertGreaterThan(0, $deployment['body']['sourceSize']);
        $this->assertGreaterThan(0, $deployment['body']['buildSize']);
        $totalSize = $deployment['body']['sourceSize'] + $deployment['body']['buildSize'];
        $this->assertEquals($totalSize, $deployment['body']['totalSize']);

        $this->cleanupFunction($functionId);
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
            'code' => $this->packageFunction('basic'),
            'activate' => true
        ]);

        $this->assertEquals(202, $deployment['headers']['status-code']);
        $this->assertNotEmpty($deployment['body']['$id']);
        $this->assertEquals('waiting', $deployment['body']['status']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($deployment['body']['$createdAt']));
        $this->assertEquals('index.js', $deployment['body']['entrypoint']);

        $deploymentIdActive = $deployment['body']['$id'] ?? '';

        $this->assertEventually(function () use ($functionId, $deploymentIdActive) {
            $deployment = $this->getDeployment($functionId, $deploymentIdActive);

            $this->assertEquals('ready', $deployment['body']['status']);
        }, 50000, 500);

        $deployment = $this->createDeployment($functionId, [
            'code' => $this->packageFunction('basic'),
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
        $this->assertEquals($deploymentIdActive, $function['body']['deploymentId']);
        $this->assertNotEquals($deploymentIdInactive, $function['body']['deploymentId']);

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
    #[Retry(count: 3)]
    public function testCancelDeploymentBuild($data): void
    {
        $functionId = $data['functionId'];

        $deployment = $this->createDeployment($functionId, [
            'code' => $this->packageFunction('basic'),
            'activate' => 'false'
        ]);

        $deploymentId = $deployment['body']['$id'] ?? '';

        $this->assertEquals(202, $deployment['headers']['status-code']);
        $this->assertNotEmpty($deployment['body']['$id']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($deployment['body']['$createdAt']));
        $this->assertEquals('index.js', $deployment['body']['entrypoint']);

        $this->assertEventually(function () use ($functionId, $deploymentId) {
            $deployment = $this->getDeployment($functionId, $deploymentId);

            $this->assertEquals(200, $deployment['headers']['status-code']);
            $this->assertEquals('building', $deployment['body']['status']);
        }, 100000, 250);

        $deployment = $this->cancelDeployment($functionId, $deploymentId);
        $this->assertEquals(200, $deployment['headers']['status-code']);
        $this->assertEquals('canceled', $deployment['body']['status']);

        // Ensures worker got eventually aware of cancellation and reacted properly
        $this->assertEventually(function () use ($functionId, $deploymentId) {
            $deployment = $this->getDeployment($functionId, $deploymentId);
            $this->assertEquals(200, $deployment['headers']['status-code']);
            $this->assertStringContainsString('Build has been canceled.', $deployment['body']['buildLogs']);
        });

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

        $folder = 'large';
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
            $curlFile = new \CURLFile('data://' . $mimeType . ';base64,' . base64_encode(@fread($handle, $chunkSize)), $mimeType, 'large-fx.tar.gz');
            $headers['content-range'] = 'bytes ' . ($counter * $chunkSize) . '-' . min(((($counter * $chunkSize) + $chunkSize) - 1), $size - 1) . '/' . $size;
            if (!empty($id)) {
                $headers['x-appwrite-id'] = $id;
            }
            $largeTag = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/deployments', array_merge($headers, $this->getHeaders()), [
                'entrypoint' => 'index.js',
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
        $this->assertEquals('index.js', $largeTag['body']['entrypoint']);
        $this->assertGreaterThan(1024 * 1024 * 5, $largeTag['body']['sourceSize']); // ~7MB video file
        $this->assertLessThan(1024 * 1024 * 10, $largeTag['body']['sourceSize']); // ~7MB video file

        $deploymentSize = $largeTag['body']['sourceSize'];
        $deploymentId = $largeTag['body']['$id'];

        $this->assertEventually(function () use ($functionId, $deploymentId, $deploymentSize) {
            $deployment = $this->getDeployment($functionId, $deploymentId);

            $this->assertEquals(200, $deployment['headers']['status-code']);
            $this->assertEquals('ready', $deployment['body']['status']);
            $this->assertEquals($deploymentSize, $deployment['body']['sourceSize']);
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
        $this->assertEquals($data['deploymentId'], $response['body']['deploymentId']);

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
        $this->assertArrayHasKey('sourceSize', $deployments['body']['deployments'][0]);
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
                Query::select(['status'])->toString(),
            ],
        ]);

        $this->assertEquals($deployments['headers']['status-code'], 200);
        $this->assertArrayHasKey('status', $deployments['body']['deployments'][0]);
        $this->assertArrayHasKey('status', $deployments['body']['deployments'][1]);
        $this->assertArrayNotHasKey('sourceSize', $deployments['body']['deployments'][0]);
        $this->assertArrayNotHasKey('sourceSize', $deployments['body']['deployments'][1]);

        // Extra select query check, for attribute not allowed by filter queries
        $deployments = $this->listDeployments($functionId, [
            'queries' => [
                Query::select(['buildLogs'])->toString(),
            ],
        ]);
        $this->assertEquals($deployments['headers']['status-code'], 200);
        $this->assertArrayHasKey('buildLogs', $deployments['body']['deployments'][0]);
        $this->assertArrayHasKey('buildLogs', $deployments['body']['deployments'][1]);
        $this->assertArrayNotHasKey('sourceSize', $deployments['body']['deployments'][0]);
        $this->assertArrayNotHasKey('sourceSize', $deployments['body']['deployments'][1]);

        $deployments = $this->listDeployments($functionId, [
            'queries' => [
                Query::offset(1)->toString(),
            ],
        ]);

        $this->assertEquals(200, $deployments['headers']['status-code']);
        $this->assertCount(2, $deployments['body']['deployments']);

        $deployments = $this->listDeployments($functionId);

        $this->assertIsArray($deployments['body']['deployments']);
        $this->assertEquals(200, $deployments['headers']['status-code']);

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
                    Query::greaterThan('sourceSize', 10000)->toString(),
                ],
            ]
        );

        $this->assertEquals($deployments['headers']['status-code'], 200);
        $this->assertEquals(1, $deployments['body']['total']);

        $deployments = $this->listDeployments(
            $functionId,
            [
                'queries' => [
                    Query::greaterThan('sourceSize', 0)->toString(),
                ],
            ]
        );

        $this->assertEquals($deployments['headers']['status-code'], 200);
        $this->assertEquals(3, $deployments['body']['total']);

        $deployments = $this->listDeployments(
            $functionId,
            [
                'queries' => [
                    Query::greaterThan('sourceSize', -100)->toString(),
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
        $this->assertNotEmpty($deployments['body']['deployments'][0]['sourceSize']);

        $deploymentId = $deployments['body']['deployments'][0]['$id'];
        $deploymentSize = $deployments['body']['deployments'][0]['sourceSize'];

        $deployments = $this->listDeployments(
            $functionId,
            [
                'queries' => [
                    Query::equal('sourceSize', [$deploymentSize])->toString(),
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
            $this->assertEquals($deploymentSize, $deployment['sourceSize']);
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
        $this->assertGreaterThan(0, $deployment['body']['buildDuration']);
        $this->assertNotEmpty($deployment['body']['status']);
        $this->assertNotEmpty($deployment['body']['buildLogs']);
        $this->assertArrayHasKey('sourceSize', $deployment['body']);
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

        $this->assertNotEmpty($execution['body']['responseHeaders']);

        $executionIdHeader = null;
        foreach ($execution['body']['responseHeaders'] as $header) {
            if ($header['name'] === 'x-appwrite-execution-id') {
                $executionIdHeader = $header['value'];
                break;
            }
        }
        $this->assertNotEmpty($executionIdHeader);
        $this->assertEquals($execution['body']['$id'], $executionIdHeader);

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
        $this->assertStringContainsString('Node.js', $execution['body']['responseBody']);
        $this->assertStringContainsString('22', $execution['body']['responseBody']);
        $this->assertStringContainsString('Global Variable Value', $execution['body']['responseBody']);
        // $this->assertStringContainsString('êä', $execution['body']['responseBody']); // tests unknown utf-8 chars
        $this->assertNotEmpty($execution['body']['errors']);
        $this->assertNotEmpty($execution['body']['logs']);
        $this->assertLessThan(10, $execution['body']['duration']);

        $executionId = $execution['body']['$id'] ?? '';

        /** Test create execution with HEAD method */
        $execution = $this->createExecution($data['functionId'], [
            'async' => 'false',
            'method' => 'HEAD',
        ]);

        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertEquals('completed', $execution['body']['status']);
        $this->assertEquals(200, $execution['body']['responseStatusCode']);
        $this->assertIsArray($execution['body']['responseHeaders']);
        $this->assertEmpty($execution['body']['responseBody']); // For HEAD requests, response body is empty

        /** Delete execution */
        $execution = $this->client->call(Client::METHOD_DELETE, '/functions/' . $data['functionId'] . '/executions/' . $execution['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);
        $this->assertEquals(204, $execution['headers']['status-code']);

        /** Test create execution with 400 status code */
        $execution = $this->createExecution($data['functionId'], [
            'async' => 'false',
            'path' => '/?code=400'
        ]);

        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertEquals('completed', $execution['body']['status']);
        $this->assertEquals(400, $execution['body']['responseStatusCode']);

        /** Delete execution */
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
        $this->assertEquals($data['deploymentId'], $executions['body']['executions'][0]['deploymentId']);

        /**
         * Test for SUCCESS with total=false
         */
        $executionsWithIncludeTotalFalse = $this->listExecutions($data['functionId'], ['total' => false]);

        $this->assertEquals(200, $executionsWithIncludeTotalFalse['headers']['status-code']);
        $this->assertIsArray($executionsWithIncludeTotalFalse['body']);
        $this->assertIsArray($executionsWithIncludeTotalFalse['body']['executions']);
        $this->assertIsInt($executionsWithIncludeTotalFalse['body']['total']);
        $this->assertEquals(0, $executionsWithIncludeTotalFalse['body']['total']);
        $this->assertGreaterThan(0, count($executionsWithIncludeTotalFalse['body']['executions']));

        $executions = $this->listExecutions($data['functionId'], [
            'queries' => [
                Query::equal('deploymentId', [$data['deploymentId']])->toString(),
            ],
        ]);

        $this->assertEquals(200, $executions['headers']['status-code']);
        $this->assertEquals(1, $executions['body']['total']);
        $this->assertIsArray($executions['body']['executions']);
        $this->assertCount(1, $executions['body']['executions']);

        $executions = $this->listExecutions($data['functionId'], [
            'queries' => [
                Query::equal('deploymentId', ['some-random-id'])->toString(),
            ],
        ]);

        $this->assertEquals(200, $executions['headers']['status-code']);
        $this->assertEquals(0, $executions['body']['total']);
        $this->assertIsArray($executions['body']['executions']);
        $this->assertCount(0, $executions['body']['executions']);

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
        $this->assertStringContainsString('Node.js', $execution['body']['responseBody']);
        $this->assertStringContainsString('22', $execution['body']['responseBody']);
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

        $this->assertEquals(200, $execution['headers']['status-code']);
        $this->assertEquals($data['executionId'], $execution['body']['$id']);
        $this->assertEquals($data['deploymentId'], $execution['body']['deploymentId']);

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
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
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

        // Test execution ID and client IP
        $executionId = $execution['body']['$id'] ?? '';
        $this->assertNotEmpty($output['APPWRITE_FUNCTION_EXECUTION_ID']);
        $this->assertEquals($executionId, $output['APPWRITE_FUNCTION_EXECUTION_ID']);
        $this->assertNotEmpty($output['APPWRITE_FUNCTION_CLIENT_IP']);

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
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
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
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
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
            'name' => 'Test timeout execution',
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
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
        // Most disabled to keep tests fast
        return [
            // ['folder' => 'php-fn', 'name' => 'php-8.0', 'entrypoint' => 'index.php', 'runtimeName' => 'PHP', 'runtimeVersion' => '8.0'],
            ['folder' => 'node', 'name' => 'node-22', 'entrypoint' => 'index.js', 'runtimeName' => 'Node.js', 'runtimeVersion' => '22'],
            // ['folder' => 'python', 'name' => 'python-3.9', 'entrypoint' => 'main.py', 'runtimeName' => 'Python', 'runtimeVersion' => '3.9'],
            // ['folder' => 'ruby', 'name' => 'ruby-3.1', 'entrypoint' => 'main.rb', 'runtimeName' => 'Ruby', 'runtimeVersion' => '3.1'],
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
            'async' => 'false'
        ]);

        $output = json_decode($execution['body']['responseBody'], true);
        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertEquals(200, $execution['body']['responseStatusCode']);
        $this->assertEquals('OK', $execution['body']['responseBody']);
        $this->assertEmpty($execution['body']['logs']);
        $this->assertEmpty($execution['body']['errors']);

        $executionId = $execution['body']['$id'] ?? '';

        $executions = $this->listExecutions($functionId);

        $this->assertEquals($executions['headers']['status-code'], 200);
        $this->assertEquals($executions['body']['total'], 1);
        $this->assertIsArray($executions['body']['executions']);
        $this->assertCount(1, $executions['body']['executions']);
        $this->assertEquals($executions['body']['executions'][0]['$id'], $executionId);
        $this->assertEquals($executions['body']['executions'][0]['trigger'], 'http');
        $this->assertEquals(200, $executions['body']['executions'][0]['responseStatusCode']);
        $this->assertEmpty($executions['body']['executions'][0]['responseBody']);

        $this->cleanupFunction($functionId);
    }

    public function testCreateCustomExecutionBinaryResponse()
    {
        $functionId = $this->setupFunction([
            'functionId' => ID::unique(),
            'name' => 'Test Binary executions',
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
            'timeout' => 15,
            'execute' => ['any']
        ]);
        $this->setupDeployment($functionId, [
            'code' => $this->packageFunction('binary-response'),
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
            'name' => 'Test Binary executions',
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
            'timeout' => 15,
            'execute' => ['any']
        ]);
        $this->setupDeployment($functionId, [
            'code' => $this->packageFunction('binary-request'),
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

    public function testGetRuntimes()
    {
        $runtimes = $this->client->call(Client::METHOD_GET, '/functions/runtimes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

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
            'name' => 'Test Event executions',
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
            'events' => [
                'users.*.create',
            ],
            'timeout' => 15,
        ]);
        $this->setupDeployment($functionId, [
            'code' => $this->packageFunction('event-handler'),
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
            $this->assertNotEmpty($lastExecution['$id']);
            $headers = array_column($lastExecution['requestHeaders'] ?? [], 'value', 'name');
            $this->assertEmpty($headers['x-appwrite-client-ip'] ?? '');
        }, 20_000, 500);

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
            'name' => 'Test Scopes executions',
            'commands' => 'bash setup.sh && npm install',
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
            'scopes' => ['users.read'],
            'timeout' => 15,
        ]);

        $deploymentId = $this->setupDeployment($functionId, [
            'code' => $this->packageFunction('dynamic-api-key'),
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
            'name' => 'Test Cookie executions',
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
            'timeout' => 15,
        ]);
        $this->assertNotEmpty($functionId);

        $deploymentId = $this->setupDeployment($functionId, [
            'code' => $this->packageFunction('cookies'),
            'activate' => true
        ]);
        $this->assertNotEmpty($deploymentId);

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

        $deployment = $this->getDeployment($functionId, $deploymentId);
        $this->assertEquals(200, $deployment['headers']['status-code']);
        $this->assertGreaterThan(0, $deployment['body']['sourceSize']);
        $this->assertGreaterThan(0, $deployment['body']['buildSize']);
        $totalSize = $deployment['body']['sourceSize'] + $deployment['body']['buildSize'];
        $this->assertEquals($totalSize, $deployment['body']['totalSize']);

        $this->cleanupFunction($functionId);
    }

    public function testFunctionsDomain()
    {
        $functionId = $this->setupFunction([
            'functionId' => ID::unique(),
            'name' => 'Test Cookie executions',
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
            'timeout' => 15,
            'execute' => ['any']
        ]);

        $domain = $this->setupFunctionDomain($functionId);

        $this->setupDeployment($functionId, [
            'code' => $this->packageFunction('cookies'),
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

        $this->assertArrayHasKey('x-appwrite-execution-id', $response['headers']);
        $this->assertNotEmpty($response['headers']['x-appwrite-execution-id']);

        // Async execution document creation
        $this->assertEventually(function () use ($functionId) {
            $executions = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/executions', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), []);

            $this->assertEquals(200, $executions['headers']['status-code']);
            $this->assertEquals(1, count($executions['body']['executions']));
        });

        $this->assertEventually(function () use ($functionId) {
            $response = $this->getUsage($functionId, [
                'range' => '24h'
            ]);

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals(24, count($response['body']));
            $this->assertEquals('24h', $response['body']['range']);
            $this->assertEquals(1, $response['body']['executionsTotal']);
        }, 25000, 1000);

        $this->cleanupFunction($functionId);
    }

    public function testFunctionsDomainBinaryResponse()
    {
        $functionId = $this->setupFunction([
            'functionId' => ID::unique(),
            'name' => 'Test Binary executions',
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
            'timeout' => 15,
            'execute' => ['any']
        ]);

        $domain = $this->setupFunctionDomain($functionId);

        $this->setupDeployment($functionId, [
            'code' => $this->packageFunction('binary-response'),
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
            'name' => 'Test Binary executions',
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
            'timeout' => 15,
            'execute' => ['any']
        ]);

        $domain = $this->setupFunctionDomain($functionId);

        $this->setupDeployment($functionId, [
            'code' => $this->packageFunction('binary-request'),
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
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
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
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
            'timeout' => 15,
            'execute' => ['any']
        ]);

        $function2Id = $this->setupFunction([
            'functionId' => ID::unique(),
            'name' => 'Test2',
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
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
            'runtime' => 'node-22',
            'name' => 'Logging Test',
            'entrypoint' => 'index.js',
            'logging' => false,
            'execute' => ['any']
        ]);

        $this->assertEquals(201, $function['headers']['status-code']);
        $this->assertFalse($function['body']['logging']);
        $this->assertNotEmpty($function['body']['$id']);

        $functionId = $function['body']['$id'] ?? '';

        $domain = $this->setupFunctionDomain($functionId);

        $this->setupDeployment($functionId, [
            'code' => $this->packageFunction('basic'),
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
        $domain = $this->getFunctionDomain($functionId);

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

    public function testFunctionSpecifications()
    {
        // Check if the function specifications are correctly set in builds
        $function = $this->createFunction([
            'functionId' => ID::unique(),
            'runtime' => 'node-22',
            'name' => 'Specification Test',
            'entrypoint' => 'index.js',
            'logging' => false,
            'execute' => ['any'],
            'specification' => Specification::S_2VCPU_2GB,
            'commands' => 'echo $APPWRITE_FUNCTION_MEMORY:$APPWRITE_FUNCTION_CPUS',
        ]);

        $this->assertEquals(201, $function['headers']['status-code']);
        $this->assertEquals(Specification::S_2VCPU_2GB, $function['body']['specification']);
        $this->assertNotEmpty($function['body']['$id']);

        $functionId = $functionId = $function['body']['$id'] ?? '';

        $deploymentId = $this->setupDeployment($functionId, [
            'code' => $this->packageFunction('basic'),
            'activate' => true
        ]);

        $this->assertEventually(function () use ($functionId, $deploymentId) {
            $deployment = $this->getDeployment($functionId, $deploymentId);
            $this->assertTrue(str_contains($deployment['body']['buildLogs'], '2048:2'));
        }, 10000, 500);

        // Check if the function specifications are correctly set in executions
        $execution = $this->createExecution($functionId);

        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertNotEmpty($execution['body']['$id']);

        $executionResponse = json_decode($execution['body']['responseBody'], true);
        $this->assertEquals('2048', $executionResponse['APPWRITE_FUNCTION_MEMORY']);
        $this->assertEquals('2', $executionResponse['APPWRITE_FUNCTION_CPUS']);

        $this->cleanupFunction($functionId);
    }

    public function testDuplicateDeployment(): void
    {
        $functionId = $this->setupFunction([
            'functionId' => ID::unique(),
            'runtime' => 'node-22',
            'name' => 'Duplicate Deployment Test',
            'entrypoint' => 'index.js',
            'commands' => ''
        ]);
        $this->assertNotEmpty($functionId);

        $deploymentId1 = $this->setupDeployment($functionId, [
            'code' => $this->packageFunction('basic'),
            'activate' => true
        ]);
        $this->assertNotEmpty($deploymentId1);

        $execution = $this->createExecution($functionId);
        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertStringContainsString('APPWRITE_FUNCTION_ID', $execution['body']['responseBody']);

        $function = $this->updateFunction($functionId, [
            'runtime' => 'node-22',
            'name' => 'Duplicate Deployment Test',
            'entrypoint' => 'index.js',
            'commands' => 'rm index.js && mv maintenance.js index.js'
        ]);
        $this->assertEquals(200, $function['headers']['status-code']);
        $this->assertStringContainsString('maintenance.js', $function['body']['commands']);

        $deployment = $this->createDuplicateDeployment($functionId, $deploymentId1);
        $this->assertEquals(202, $deployment['headers']['status-code']);

        $deploymentId2 = $deployment['body']['$id'];
        $this->assertNotEmpty($deploymentId2);

        $deployment = $this->getDeployment($functionId, $deploymentId2);
        $this->assertEquals(200, $deployment['headers']['status-code']);
        $this->assertGreaterThan(0, $deployment['body']['sourceSize']);
        $this->assertEquals(0, $deployment['body']['buildSize']);
        $this->assertEquals($deployment['body']['sourceSize'], $deployment['body']['totalSize']);

        $this->assertEventually(function () use ($functionId, $deploymentId2) {
            $function = $this->getFunction($functionId);
            $this->assertEquals($deploymentId2, $function['body']['deploymentId']);
        }, 50000, 500);

        $execution = $this->createExecution($functionId);
        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertStringContainsString('Maintenance', $execution['body']['responseBody']);

        $deployment = $this->getDeployment($functionId, $deploymentId2);
        $this->assertEquals(200, $deployment['headers']['status-code']);
        $this->assertGreaterThan(0, $deployment['body']['sourceSize']);
        $this->assertGreaterThan(0, $deployment['body']['buildSize']);
        $totalSize = $deployment['body']['sourceSize'] + $deployment['body']['buildSize'];
        $this->assertEquals($totalSize, $deployment['body']['totalSize']);

        $this->cleanupFunction($functionId);
    }

    public function testUpdateDeploymentStatus(): void
    {

        $functionId = $this->setupFunction([
            'functionId' => ID::unique(),
            'runtime' => 'node-22',
            'name' => 'Re-activate Test',
            'entrypoint' => 'index.js',
        ]);
        $this->assertNotEmpty($functionId);

        $function = $this->getFunction($functionId);
        $this->assertEquals(200, $function['headers']['status-code']);
        $this->assertArrayHasKey('latestDeploymentId', $function['body']);
        $this->assertArrayHasKey('latestDeploymentCreatedAt', $function['body']);
        $this->assertArrayHasKey('latestDeploymentStatus', $function['body']);
        $this->assertEmpty($function['body']['latestDeploymentId']);
        $this->assertEmpty($function['body']['latestDeploymentCreatedAt']);
        $this->assertEmpty($function['body']['latestDeploymentStatus']);

        $deploymentId1 = $this->setupDeployment($functionId, [
            'code' => $this->packageFunction('cookies'),
            'activate' => true
        ]);
        $this->assertNotEmpty($deploymentId1);

        $function = $this->getFunction($functionId);
        $this->assertEquals(200, $function['headers']['status-code']);
        $this->assertEquals($deploymentId1, $function['body']['latestDeploymentId']);
        $this->assertEquals('ready', $function['body']['latestDeploymentStatus']);

        $execution = $this->createExecution($functionId, [
            'headers' => [ 'cookie' => 'cookieName=cookieValue' ]
        ]);
        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertNotEmpty($execution['body']['$id']);
        $this->assertStringContainsString('cookieValue', $execution['body']['responseBody']);

        $deploymentId2 = $this->setupDeployment($functionId, [
            'code' => $this->packageFunction('basic'),
            'activate' => true
        ]);
        $this->assertNotEmpty($deploymentId2);

        $function = $this->getFunction($functionId);
        $this->assertEquals(200, $function['headers']['status-code']);
        $this->assertEquals($deploymentId2, $function['body']['latestDeploymentId']);
        $this->assertEquals('ready', $function['body']['latestDeploymentStatus']);

        $execution = $this->createExecution($functionId);
        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertNotEmpty($execution['body']['$id']);
        $this->assertStringContainsString('UNICODE_TEST', $execution['body']['responseBody']);

        $function = $this->getFunction($functionId);
        $this->assertEquals(200, $function['headers']['status-code']);
        $this->assertEquals($deploymentId2, $function['body']['deploymentId']);
        $this->assertEquals($deploymentId2, $function['body']['latestDeploymentId']);
        $this->assertEquals('ready', $function['body']['latestDeploymentStatus']);

        $function = $this->updateFunctionDeployment($functionId, $deploymentId1);
        $this->assertEquals(200, $function['headers']['status-code']);
        $this->assertEquals($deploymentId1, $function['body']['deploymentId']);

        $function = $this->getFunction($functionId);
        $this->assertEquals(200, $function['headers']['status-code']);
        $this->assertEquals($deploymentId1, $function['body']['deploymentId']);
        $this->assertEquals($deploymentId2, $function['body']['latestDeploymentId']);
        $this->assertEquals('ready', $function['body']['latestDeploymentStatus']);

        $execution = $this->createExecution($functionId, [
            'headers' => [ 'cookie' => 'cookieName=cookieValue' ]
        ]);
        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertNotEmpty($execution['body']['$id']);
        $this->assertStringContainsString('cookieValue', $execution['body']['responseBody']);

        $deployment = $this->deleteDeployment($functionId, $deploymentId2);
        $this->assertEquals(204, $deployment['headers']['status-code']);

        $function = $this->getFunction($functionId);
        $this->assertEquals(200, $function['headers']['status-code']);
        $this->assertEquals($deploymentId1, $function['body']['latestDeploymentId']);
        $this->assertEquals('ready', $function['body']['latestDeploymentStatus']);

        $this->cleanupFunction($functionId);
    }

    #[Retry(count: 3)]
    public function testErrorPages(): void
    {
        // non-existent domain
        $domain = 'non-existent-page.functions.localhost';

        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $domain);

        $response = $proxyClient->call(Client::METHOD_GET, '/');

        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertStringContainsString('Nothing is here yet', $response['body']);
        $this->assertStringContainsString('Start with this domain', $response['body']);

        // failed deployment
        $functionId = $this->setupFunction([
            'functionId' => ID::unique(),
            'name' => 'Test Error Pages',
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
            'timeout' => 15,
            'commands' => 'cd non-existing-directory',
            'execute' => ['any']
        ]);

        $domain = $this->setupFunctionDomain($functionId);
        $proxyClient->setEndpoint('http://' . $domain);

        $deployment = $this->createDeployment($functionId, [
            'code' => $this->packageFunction('basic'),
            'activate' => true
        ]);

        $this->assertEquals(202, $deployment['headers']['status-code']);

        $response = $proxyClient->call(Client::METHOD_GET, '/', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ]));

        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertStringContainsString('No active deployments', $response['body']);
        $this->assertStringContainsString('View deployments', $response['body']);

        // canceled deployment
        $deployment = $this->createDeployment($functionId, [
            'code' => $this->packageFunction('basic'),
            'activate' => true
        ]);

        $deploymentId = $deployment['body']['$id'] ?? '';
        $this->assertEquals(202, $deployment['headers']['status-code']);

        $deployment = $this->cancelDeployment($functionId, $deploymentId);
        $this->assertEquals(200, $deployment['headers']['status-code']);
        $this->assertEquals('canceled', $deployment['body']['status']);

        $response = $proxyClient->call(Client::METHOD_GET, '/', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ]));

        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertStringContainsString('No active deployments', $response['body']);
        $this->assertStringContainsString('View deployments', $response['body']);

        $this->cleanupFunction($functionId);
    }

    public function testErrorPagesPermissions(): void
    {
        $functionId = $this->setupFunction([
            'functionId' => ID::unique(),
            'name' => 'Test Error Pages',
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
            'timeout' => 15,
            'commands' => '',
            'execute' => ['users']
        ]);

        $domain = $this->setupFunctionDomain($functionId);
        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $domain);

        $deploymentId = $this->setupDeployment($functionId, [
            'code' => $this->packageFunction('basic'),
            'activate' => true
        ]);
        $this->assertNotEmpty($deploymentId);

        $response = $proxyClient->call(Client::METHOD_GET, '/', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ]));

        $this->assertEquals(401, $response['headers']['status-code']);
        $this->assertStringContainsString('Execution not permitted', $response['body']);
        $this->assertStringContainsString('View settings', $response['body']);

        $this->cleanupFunction($functionId);
    }

    public function testErrorPagesEmptyBody(): void
    {
        $functionId = $this->setupFunction([
            'functionId' => ID::unique(),
            'name' => 'Test Error Pages',
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
            'timeout' => 15,
            'commands' => '',
            'execute' => ['any']
        ]);

        $domain = $this->setupFunctionDomain($functionId);
        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $domain);

        $deploymentId = $this->setupDeployment($functionId, [
            'code' => $this->packageFunction('basic'),
            'activate' => true
        ]);
        $this->assertNotEmpty($deploymentId);

        $response = $proxyClient->call(Client::METHOD_GET, '/custom-response?code=404', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ]));
        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertStringContainsString('Error 404', $response['body']);
        $this->assertStringContainsString('does not exist', $response['body']);

        $response = $proxyClient->call(Client::METHOD_GET, '/custom-response?code=504', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ]));
        $this->assertEquals(504, $response['headers']['status-code']);
        $this->assertStringContainsString('Error 504', $response['body']);
        $this->assertStringContainsString('respond in time', $response['body']);

        $response = $proxyClient->call(Client::METHOD_GET, '/custom-response?code=400', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ]));
        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertStringContainsString('Error 400', $response['body']);
        $this->assertStringContainsString('unexpected client error', $response['body']);

        $response = $proxyClient->call(Client::METHOD_GET, '/custom-response?code=500', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ]));
        $this->assertEquals(500, $response['headers']['status-code']);
        $this->assertStringContainsString('Error 500', $response['body']);
        $this->assertStringContainsString('unexpected server error', $response['body']);

        $response = $proxyClient->call(Client::METHOD_GET, '/custom-response?code=400&body=CustomError400', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ]));
        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertStringContainsString('CustomError400', $response['body']);

        $response = $proxyClient->call(Client::METHOD_GET, '/custom-response?code=500&body=CustomError500', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ]));
        $this->assertEquals(500, $response['headers']['status-code']);
        $this->assertStringContainsString('CustomError500', $response['body']);

        $this->cleanupFunction($functionId);
    }

    public function testLogAndErrorTruncation(): void
    {
        $functionId = $this->setupFunction([
            'functionId' => ID::unique(),
            'name' => 'Test Log Truncation',
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
            'timeout' => 15,
        ]);

        $this->setupDeployment($functionId, [
            'code' => $this->packageFunction('log-error-truncation'),
            'activate' => true
        ]);

        $execution = $this->createExecution($functionId, [
            'async' => 'false'
        ]);

        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertEquals(200, $execution['body']['responseStatusCode']);

        // Verify logs are truncated and warning message is present at the beginning
        $logs = $execution['body']['logs'];
        $this->assertLessThanOrEqual(APP_FUNCTION_LOG_LENGTH_LIMIT, strlen($logs));
        $this->assertStringStartsWith('[WARNING] Logs truncated', $logs);

        $this->assertStringNotContainsString('z', $logs);
        $this->assertStringContainsString('a', $logs);

        // Verify errors are truncated and warning message is present at the beginning
        $errors = $execution['body']['errors'];
        $this->assertLessThanOrEqual(APP_FUNCTION_ERROR_LENGTH_LIMIT, strlen($errors));
        $this->assertStringStartsWith('[WARNING] Errors truncated', $errors);

        $this->assertStringNotContainsString('z', $errors);
        $this->assertStringContainsString('a', $errors);

        $this->cleanupFunction($functionId);
    }
}
