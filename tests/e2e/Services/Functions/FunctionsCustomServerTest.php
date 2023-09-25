<?php

namespace Tests\E2E\Services\Functions;

use Appwrite\Tests\Retry;
use CURLFile;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\DateTime;
use Utopia\Database\Helpers\ID;
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
            'queries' => [ 'limit(1)' ]
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertCount(1, $response['body']['functions']);

        $response = $this->client->call(Client::METHOD_GET, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [ 'offset(1)' ]
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertCount(0, $response['body']['functions']);

        $response = $this->client->call(Client::METHOD_GET, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [ 'equal("enabled", true)' ]
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertCount(1, $response['body']['functions']);

        $response = $this->client->call(Client::METHOD_GET, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [ 'equal("enabled", false)' ]
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
            'queries' => [ 'cursorAfter("' . $functions['body']['functions'][0]['$id'] . '")' ],
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertCount(1, $response['body']['functions']);
        $this->assertEquals($response['body']['functions'][0]['name'], 'Test 2');

        $response = $this->client->call(Client::METHOD_GET, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [ 'cursorBefore("' . $functions['body']['functions'][1]['$id'] . '")' ],
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
            'queries' => [ 'cursorAfter("unknown")' ],
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

        // Poll until deployment is built
        while (true) {
            $deployment = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/deployments/' . $deploymentId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);

            if (
                $deployment['headers']['status-code'] >= 400
                || \in_array($deployment['body']['status'], ['ready', 'failed'])
            ) {
                break;
            }

            \sleep(1);
        }

        $this->assertEquals(200, $deployment['headers']['status-code']);
        $this->assertEquals('ready', $deployment['body']['status']);

        return array_merge($data, ['deploymentId' => $deploymentId]);
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
                'activate' => true
            ]);
            $counter++;
            $id = $largeTag['body']['$id'];
        }
        @fclose($handle);

        $this->assertEquals(202, $largeTag['headers']['status-code']);
        $this->assertNotEmpty($largeTag['body']['$id']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($largeTag['body']['$createdAt']));
        $this->assertEquals('index.php', $largeTag['body']['entrypoint']);
        $this->assertGreaterThan(10000, $largeTag['body']['size']);

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
        $this->assertEquals($function['body']['total'], 2);
        $this->assertIsArray($function['body']['deployments']);
        $this->assertCount(2, $function['body']['deployments']);

        /**
         * Test search queries
         */
        $function = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/deployments', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders(), [
            'search' => $data['functionId']
        ]));

        $this->assertEquals($function['headers']['status-code'], 200);
        $this->assertEquals(2, $function['body']['total']);
        $this->assertIsArray($function['body']['deployments']);
        $this->assertCount(2, $function['body']['deployments']);
        $this->assertEquals($function['body']['deployments'][0]['$id'], $data['deploymentId']);

        $function = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/deployments', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [ 'limit(1)' ]
        ]);

        $this->assertEquals($function['headers']['status-code'], 200);
        $this->assertCount(1, $function['body']['deployments']);

        $function = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/deployments', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [ 'offset(1)' ]
        ]);

        $this->assertEquals($function['headers']['status-code'], 200);
        $this->assertCount(1, $function['body']['deployments']);

        $function = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/deployments', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [ 'equal("entrypoint", "index.php")' ]
        ]);

        $this->assertEquals($function['headers']['status-code'], 200);
        $this->assertCount(2, $function['body']['deployments']);

        $function = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/deployments', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [ 'equal("entrypoint", "index.js")' ]
        ]);

        $this->assertEquals($function['headers']['status-code'], 200);
        $this->assertCount(0, $function['body']['deployments']);

        $function = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/deployments', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders(), [
            'search' => 'Test'
        ]));

        $this->assertEquals($function['headers']['status-code'], 200);
        $this->assertEquals(2, $function['body']['total']);
        $this->assertIsArray($function['body']['deployments']);
        $this->assertCount(2, $function['body']['deployments']);
        $this->assertEquals($function['body']['deployments'][0]['$id'], $data['deploymentId']);

        $function = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/deployments', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders(), [
            'search' => 'php-8.0'
        ]));

        $this->assertEquals($function['headers']['status-code'], 200);
        $this->assertEquals(2, $function['body']['total']);
        $this->assertIsArray($function['body']['deployments']);
        $this->assertCount(2, $function['body']['deployments']);
        $this->assertEquals($function['body']['deployments'][0]['$id'], $data['deploymentId']);

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
            'queries' => [ 'limit(1)' ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(1, $response['body']['executions']);

        $response = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [ 'offset(1)' ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(0, $response['body']['executions']);

        $response = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [ 'equal("trigger", "http")' ]
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
            'timeout' => $timeout,
        ]);

        $functionId = $function['body']['$id'] ?? '';

        $this->assertEquals(201, $function['headers']['status-code']);

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

        // Poll until deployment is built
        while (true) {
            $deployment = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/deployments/' . $deploymentId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);

            if (
                $deployment['headers']['status-code'] >= 400
                || \in_array($deployment['body']['status'], ['ready', 'failed'])
            ) {
                break;
            }

            \sleep(1);
        }

        $this->assertEquals('ready', $deployment['body']['status']);

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
        ], $this->getHeaders()));

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
            [ 'folder' => 'php-fn', 'name' => 'php-8.0', 'entrypoint' => 'index.php', 'runtimeName' => 'PHP', 'runtimeVersion' => '8.0' ],
            [ 'folder' => 'node', 'name' => 'node-18.0', 'entrypoint' => 'index.js', 'runtimeName' => 'Node.js', 'runtimeVersion' => '18.0' ],
            [ 'folder' => 'python', 'name' => 'python-3.9', 'entrypoint' => 'main.py', 'runtimeName' => 'Python', 'runtimeVersion' => '3.9' ],
            [ 'folder' => 'ruby', 'name' => 'ruby-3.1', 'entrypoint' => 'main.rb', 'runtimeName' => 'Ruby', 'runtimeVersion' => '3.1' ],
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
     * @depends testTimeout
     */
    public function testCreateCustomExecution(string $folder, string $name, string $entrypoint, string $runtimeName, string $runtimeVersion)
    {
        $timeout = 5;
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

        // Poll until deployment is built
        while (true) {
            $deployment = $this->client->call(Client::METHOD_GET, '/functions/' . $function['body']['$id'] . '/deployments/' . $deploymentId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);

            if (
                $deployment['headers']['status-code'] >= 400
                || \in_array($deployment['body']['status'], ['ready', 'failed'])
            ) {
                break;
            }

            \sleep(1);
        }

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

    public function testv2Function()
    {
        $timeout = 5;
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

        // Poll until deployment is built
        while (true) {
            $deployment = $this->client->call(Client::METHOD_GET, '/functions/' . $function['body']['$id'] . '/deployments/' . $deploymentId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);

            if (
                $deployment['headers']['status-code'] >= 400
                || \in_array($deployment['body']['status'], ['ready', 'failed'])
            ) {
                break;
            }

            \sleep(1);
        }

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
        $this->assertArrayHasKey('version', $runtime);
        $this->assertArrayHasKey('logo', $runtime);
        $this->assertArrayHasKey('image', $runtime);
        $this->assertArrayHasKey('base', $runtime);
        $this->assertArrayHasKey('supports', $runtime);
    }


    public function testEventTrigger()
    {
        $timeout = 5;
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

        // Poll until deployment is built
        while (true) {
            $deployment = $this->client->call(Client::METHOD_GET, '/functions/' . $function['body']['$id'] . '/deployments/' . $deploymentId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);

            if (
                $deployment['headers']['status-code'] >= 400
                || \in_array($deployment['body']['status'], ['ready', 'failed'])
            ) {
                break;
            }

            \sleep(1);
        }

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
}
