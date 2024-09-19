<?php

namespace Tests\E2E\Services\Functions;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Role;

class FunctionsCustomClientTest extends Scope
{
    use FunctionsBase;
    use ProjectCustom;
    use SideClient;

    public function testCreateFunction()
    {
        /**
         * Test for FAILURE
         */
        $function = $this->createFunction([
            'functionId' => ID::unique(),
            'name' => 'Test',
            'events' => [
                'users.*.create',
                'users.*.delete',
            ],
            'schedule' => '0 0 1 1 *',
            'timeout' => 10,
        ]);
        $this->assertEquals(401, $function['headers']['status-code']);
    }

    public function testCreateExecution()
    {
        /**
         * Test for SUCCESS
         */
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
            'schedule' => '* * * * *', // Execute every 60 seconds
            'timeout' => 10,
        ]);
        $this->setupDeployment($functionId, [
            'entrypoint' => 'index.php',
            'code' => $this->packageFunction('php'),
            'activate' => true
        ]);

        // Deny create async execution as guest
        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/executions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'async' => true,
        ]);
        $this->assertEquals(401, $execution['headers']['status-code']);

        // Allow create async execution as user
        $execution = $this->createExecution($functionId, [
            'async' => true,
        ]);
        $this->assertEquals(202, $execution['headers']['status-code']);

        // Wait for scheduled execution
        \sleep(60);

        $this->assertEventually(function () use ($functionId) {
            $executions = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/executions', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['key'],
            ]);
            $this->assertEquals(200, $executions['headers']['status-code']);
            $this->assertCount(2, $executions['body']['executions']);

            $asyncExecution = $executions['body']['executions'][1];
            $this->assertEquals('schedule', $asyncExecution['trigger']);
            $this->assertEquals('completed', $asyncExecution['status']);
            $this->assertEquals(200, $asyncExecution['responseStatusCode']);
            $this->assertEquals('', $asyncExecution['responseBody']);
            $this->assertNotEmpty($asyncExecution['logs']);
            $this->assertNotEmpty($asyncExecution['errors']);
            $this->assertGreaterThan(0, $asyncExecution['duration']);
        }, 10000, 500);

        $this->cleanupFunction($functionId);
    }

    public function testCreateScheduledExecution(): void
    {
        /**
         * Test for SUCCESS
         */
        $functionId = $this->setupFunction([
            'functionId' => ID::unique(),
            'name' => 'Test',
            'execute' => [Role::user($this->getUser()['$id'])->toString()],
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'timeout' => 10,
            'logging' => true,
        ]);
        $this->setupDeployment($functionId, [
            'entrypoint' => 'index.php',
            'code' => $this->packageFunction('php'),
            'activate' => true
        ]);

        // Schedule execution for the future
        \date_default_timezone_set('UTC');
        $futureTime = (new \DateTime())->add(new \DateInterval('PT2M')); // 2 minute in the future
        $futureTime->setTime($futureTime->format('H'), $futureTime->format('i'), 0, 0);

        $execution = $this->createExecution($functionId, [
            'async' => true,
            'scheduledAt' => $futureTime->format(\DateTime::ATOM),
            'path' => '/custom-path',
            'method' => 'PATCH',
            'body' => 'custom-body',
            'headers' => [
                'x-custom-header' => 'custom-value'
            ]
        ]);
        $executionId = $execution['body']['$id'];

        $this->assertEquals(202, $execution['headers']['status-code']);
        $this->assertEquals('scheduled', $execution['body']['status']);
        $this->assertEquals('PATCH', $execution['body']['requestMethod']);
        $this->assertEquals('/custom-path', $execution['body']['requestPath']);
        $this->assertCount(0, $execution['body']['requestHeaders']);

        \sleep(120);

        $this->assertEventually(function () use ($functionId, $executionId) {
            $execution = $this->getExecution($functionId, $executionId);

            $this->assertEquals(200, $execution['headers']['status-code']);
            $this->assertEquals(200, $execution['body']['responseStatusCode']);
            $this->assertEquals('completed', $execution['body']['status']);
            $this->assertEquals('/custom-path', $execution['body']['requestPath']);
            $this->assertEquals('PATCH', $execution['body']['requestMethod']);
            $this->assertStringContainsString('body-is-custom-body', $execution['body']['logs']);
            $this->assertStringContainsString('custom-header-is-custom-value', $execution['body']['logs']);
            $this->assertStringContainsString('method-is-patch', $execution['body']['logs']);
            $this->assertStringContainsString('path-is-/custom-path', $execution['body']['logs']);
            $this->assertStringContainsString('user-is-' . $this->getUser()['$id'], $execution['body']['logs']);
            $this->assertStringContainsString('jwt-is-valid', $execution['body']['logs']);
            $this->assertGreaterThan(0, $execution['body']['duration']);
        }, 10000, 500);

        /* Test for FAILURE */
        // Schedule synchronous execution
        $execution = $this->createExecution($functionId, [
            'async' => false,
            'scheduledAt' =>  $futureTime->format(\DateTime::ATOM),
        ]);
        $this->assertEquals(400, $execution['headers']['status-code']);

        // Execution with seconds precision
        $execution = $this->createExecution($functionId, [
            'async' => true,
            'scheduledAt' => (new \DateTime("2100-12-08 16:12:02"))->format(\DateTime::ATOM)
        ]);
        $this->assertEquals(400, $execution['headers']['status-code']);

        // Execution with milliseconds precision
        $execution = $this->createExecution($functionId, [
            'async' => true,
            'scheduledAt' => (new \DateTime("2100-12-08 16:12:02.255"))->format(\DateTime::ATOM)
        ]);
        $this->assertEquals(400, $execution['headers']['status-code']);

        // Execution too soon
        $execution = $this->createExecution($functionId, [
            'async' => true,
            'scheduledAt' => (new \DateTime())->add(new \DateInterval('PT1S'))->format(\DateTime::ATOM)
        ]);
        $this->assertEquals(400, $execution['headers']['status-code']);

        $this->cleanupFunction($functionId, $executionId);
    }

    public function testCreateCustomExecution(): array
    {
        /**
         * Test for SUCCESS
         */
        $functionId = $this->setupFunction([
            'functionId' => ID::unique(),
            'name' => 'Test',
            'execute' => [Role::any()->toString()],
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'timeout' => 10,
        ]);
        $deploymentId = $this->setupDeployment($functionId, [
            'entrypoint' => 'index.php',
            'code' => $this->packageFunction('php-fn'),
            'activate' => true
        ]);

        $execution = $this->createExecution($functionId, [
            'body' => 'foobar',
            'async' => false
        ]);
        $output = json_decode($execution['body']['responseBody'], true);
        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertEquals(200, $execution['body']['responseStatusCode']);
        $this->assertGreaterThan(0, $execution['body']['duration']);
        $this->assertEquals('completed', $execution['body']['status']);
        $this->assertEquals($functionId, $output['APPWRITE_FUNCTION_ID']);
        $this->assertEquals('Test', $output['APPWRITE_FUNCTION_NAME']);
        $this->assertEquals($deploymentId, $output['APPWRITE_FUNCTION_DEPLOYMENT']);
        $this->assertEquals('http', $output['APPWRITE_FUNCTION_TRIGGER']);
        $this->assertEquals('PHP', $output['APPWRITE_FUNCTION_RUNTIME_NAME']);
        $this->assertEquals('8.0', $output['APPWRITE_FUNCTION_RUNTIME_VERSION']);
        $this->assertEquals(APP_VERSION_STABLE, $output['APPWRITE_VERSION']);
        $this->assertEquals('default', $output['APPWRITE_REGION']);
        $this->assertEquals('', $output['APPWRITE_FUNCTION_EVENT']);
        $this->assertEquals('foobar', $output['APPWRITE_FUNCTION_DATA']);
        $this->assertEquals($this->getUser()['$id'], $output['APPWRITE_FUNCTION_USER_ID']);
        $this->assertNotEmpty($output['APPWRITE_FUNCTION_JWT']);
        $this->assertEquals($this->getProject()['$id'], $output['APPWRITE_FUNCTION_PROJECT_ID']);

        $execution = $this->createExecution($functionId, [
            'body' => 'foobar',
            'async' => true
        ]);
        $executionId = $execution['body']['$id'];
        $this->assertEquals(202, $execution['headers']['status-code']);

        $this->assertEventually(function () use ($functionId, $executionId) {
            $execution = $this->getExecution($functionId, $executionId);
            $this->assertEquals('completed', $execution['body']['status']);
            $this->assertEquals(200, $execution['body']['responseStatusCode']);
        }, 10000, 250);

        return [
            'functionId' => $functionId
        ];
    }

    public function testCreateCustomExecutionGuest()
    {
        /**
         * Test for SUCCESS
         */
        $functionId = $this->setupFunction([
            'functionId' => ID::unique(),
            'name' => 'Test',
            'execute' => [Role::any()->toString()],
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'vars' => [
                'funcKey1' => 'funcValue1',
                'funcKey2' => 'funcValue2',
                'funcKey3' => 'funcValue3',
            ],
            'timeout' => 10,
        ]);
        $this->setupDeployment($functionId, [
            'entrypoint' => 'index.php',
            'code' => $this->packageFunction('php-fn'),
            'activate' => true
        ]);

        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/executions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'data' => 'foobar',
            'async' => true,
        ]);
        $this->assertEquals(202, $execution['headers']['status-code']);
    }

    public function testCreateExecutionNoDeployment()
    {
        $functionId = $this->setupFunction([
            'functionId' => ID::unique(),
            'name' => 'Test',
            'execute' => [],
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'timeout' => 10,
        ]);

        $execution = $this->createExecution($functionId, [
            'async' => true
        ]);
        $this->assertEquals(404, $execution['headers']['status-code']);
    }

    public function testSynchronousExecution()
    {
        /**
         * Test for SUCCESS
         */
        $functionId = $this->setupFunction([
            'functionId' => ID::unique(),
            'name' => 'Test',
            'execute' => [Role::any()->toString()],
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'timeout' => 10,
        ]);
        $deploymentId = $this->setupDeployment($functionId, [
            'entrypoint' => 'index.php',
            'code' => $this->packageFunction('php-fn'),
            'activate' => true
        ]);

        $execution = $this->createExecution($functionId, [
            'body' => 'foobar',
            // Testing default value, should be 'async' => false
        ]);
        $output = json_decode($execution['body']['responseBody'], true);
        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertEquals('completed', $execution['body']['status']);
        $this->assertEquals(200, $execution['body']['responseStatusCode']);
        $this->assertEquals($functionId, $output['APPWRITE_FUNCTION_ID']);
        $this->assertEquals('Test', $output['APPWRITE_FUNCTION_NAME']);
        $this->assertEquals($deploymentId, $output['APPWRITE_FUNCTION_DEPLOYMENT']);
        $this->assertEquals('http', $output['APPWRITE_FUNCTION_TRIGGER']);
        $this->assertEquals('PHP', $output['APPWRITE_FUNCTION_RUNTIME_NAME']);
        $this->assertEquals('8.0', $output['APPWRITE_FUNCTION_RUNTIME_VERSION']);
        $this->assertEquals(APP_VERSION_STABLE, $output['APPWRITE_VERSION']);
        $this->assertEquals('default', $output['APPWRITE_REGION']);
        $this->assertEquals('', $output['APPWRITE_FUNCTION_EVENT']);
        $this->assertEquals('foobar', $output['APPWRITE_FUNCTION_DATA']);
        $this->assertEquals($this->getUser()['$id'], $output['APPWRITE_FUNCTION_USER_ID']);
        $this->assertNotEmpty($output['APPWRITE_FUNCTION_JWT']);
        $this->assertEquals($this->getProject()['$id'], $output['APPWRITE_FUNCTION_PROJECT_ID']);
        // Client should never see logs and errors
        $this->assertEmpty($execution['body']['logs']);
        $this->assertEmpty($execution['body']['errors']);

        $this->cleanupFunction($functionId);
    }

    public function testNonOverrideOfHeaders()
    {
        $functionId = $this->setupFunction([
            'functionId' => ID::unique(),
            'name' => 'Test',
            'execute' => [Role::any()->toString()],
            'runtime' => 'node-18.0',
            'entrypoint' => 'index.js'
        ]);
        $this->setupDeployment($functionId, [
            'entrypoint' => 'index.js',
            'code' => $this->packageFunction('node'),
            'activate' => true
        ]);

        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'x-appwrite-event' => "OVERRIDDEN",
            'x-appwrite-trigger' => "OVERRIDDEN",
            'x-appwrite-user-id' => "OVERRIDDEN",
            'x-appwrite-user-jwt' => "OVERRIDDEN",
        ]);
        $output = json_decode($execution['body']['responseBody'], true);
        $this->assertNotEquals('OVERRIDDEN', $output['APPWRITE_FUNCTION_JWT']);
        $this->assertNotEquals('OVERRIDDEN', $output['APPWRITE_FUNCTION_EVENT']);
        $this->assertNotEquals('OVERRIDDEN', $output['APPWRITE_FUNCTION_TRIGGER']);
        $this->assertNotEquals('OVERRIDDEN', $output['APPWRITE_FUNCTION_USER_ID']);

        $this->cleanupFunction($functionId);
    }

    public function testListTemplates()
    {
        /**
         * Test for SUCCESS
         */
        // List all templates
        $templates = $this->client->call(Client::METHOD_GET, '/functions/templates', array_merge([
            'content-type' => 'application/json',
        ], $this->getHeaders()));

        $this->assertEquals(200, $templates['headers']['status-code']);
        $this->assertGreaterThan(0, $templates['body']['total']);
        $this->assertIsArray($templates['body']['templates']);

        foreach ($templates['body']['templates'] as $template) {
            $this->assertArrayHasKey('name', $template);
            $this->assertArrayHasKey('id', $template);
            $this->assertArrayHasKey('icon', $template);
            $this->assertArrayHasKey('tagline', $template);
            $this->assertArrayHasKey('useCases', $template);
            $this->assertArrayHasKey('vcsProvider', $template);
            $this->assertArrayHasKey('runtimes', $template);
            $this->assertArrayHasKey('variables', $template);
        }

        // List templates with pagination
        $templatesOffset = $this->client->call(Client::METHOD_GET, '/functions/templates', array_merge([
            'content-type' => 'application/json',
        ], $this->getHeaders()), [
            'limit' => 1,
            'offset' => 2
        ]);
        $this->assertEquals(200, $templatesOffset['headers']['status-code']);
        $this->assertEquals(1, $templatesOffset['body']['total']);
        $this->assertEquals($templates['body']['templates'][2]['id'], $templatesOffset['body']['templates'][0]['id']);

        // List templates with filters
        $templates = $this->client->call(Client::METHOD_GET, '/functions/templates', array_merge([
            'content-type' => 'application/json',
        ], $this->getHeaders()), [
            'useCases' => ['starter', 'ai'],
            'runtimes' => ['bun-1.0', 'dart-2.16']
        ]);
        $this->assertEquals(200, $templates['headers']['status-code']);
        $this->assertGreaterThanOrEqual(3, $templates['body']['total']);
        $this->assertIsArray($templates['body']['templates']);
        foreach ($templates['body']['templates'] as $template) {
            $this->assertContains($template['useCases'][0], ['starter', 'ai']);
        }
        $this->assertArrayHasKey('runtimes', $templates['body']['templates'][0]);
        $this->assertContains('bun-1.0', array_column($templates['body']['templates'][0]['runtimes'], 'name'));

        // List templates with pagination and filters
        $templates = $this->client->call(Client::METHOD_GET, '/functions/templates', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'limit' => 5,
            'offset' => 2,
            'useCases' => ['databases'],
            'runtimes' => ['node-16.0']
        ]);

        $this->assertEquals(200, $templates['headers']['status-code']);
        $this->assertEquals(5, $templates['body']['total']);
        $this->assertIsArray($templates['body']['templates']);
        $this->assertArrayHasKey('runtimes', $templates['body']['templates'][0]);

        foreach ($templates['body']['templates'] as $template) {
            $this->assertContains($template['useCases'][0], ['databases']);
        }

        $this->assertContains('node-16.0', array_column($templates['body']['templates'][0]['runtimes'], 'name'));

        /**
         * Test for FAILURE
         */
        // List templates with invalid limit
        $templates = $this->client->call(Client::METHOD_GET, '/functions/templates', array_merge([
            'content-type' => 'application/json',
        ], $this->getHeaders()), [
            'limit' => 5001,
            'offset' => 10,
        ]);
        $this->assertEquals(400, $templates['headers']['status-code']);

        // List templates with invalid offset
        $templates = $this->client->call(Client::METHOD_GET, '/functions/templates', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'limit' => 5,
            'offset' => 5001,
        ]);
        $this->assertEquals(400, $templates['headers']['status-code']);
    }

    public function testGetTemplate()
    {
        /**
         * Test for SUCCESS
         */
        $template = $this->getTemplate('query-neo4j-auradb');
        $this->assertEquals(200, $template['headers']['status-code']);
        $this->assertIsArray($template['body']);
        $this->assertEquals('query-neo4j-auradb', $template['body']['id']);
        $this->assertEquals('Query Neo4j AuraDB', $template['body']['name']);
        $this->assertEquals('icon-neo4j', $template['body']['icon']);
        $this->assertEquals('Graph database with focus on relations between data.', $template['body']['tagline']);
        $this->assertEquals(['databases'], $template['body']['useCases']);
        $this->assertEquals('github', $template['body']['vcsProvider']);
        $this->assertIsArray($template['body']['runtimes']);
        $this->assertIsArray($template['body']['scopes']);

        /**
         * Test for FAILURE
         */
        $template = $this->getTemplate('invalid-template-id');
        $this->assertEquals(404, $template['headers']['status-code']);
    }
}
