<?php

namespace Tests\E2E\Services\Project;

use Appwrite\Tests\Async;
use Appwrite\Tests\Async\Exceptions\Critical;
use CURLFile;
use Tests\E2E\Client;
use Utopia\Console;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\System\System;

trait VariablesBase
{
    use Async;

    protected string $stdout = '';
    protected string $stderr = '';

    // Create variable tests

    public function testCreateVariable(): void
    {
        $variable = $this->createVariable(
            ID::unique(),
            'APP_KEY',
            'my-secret-value',
        );

        $this->assertSame(201, $variable['headers']['status-code']);
        $this->assertNotEmpty($variable['body']['$id']);
        $this->assertSame('APP_KEY', $variable['body']['key']);
        $this->assertSame(true, $variable['body']['secret']);
        $this->assertSame('', $variable['body']['value']);
        $this->assertSame('project', $variable['body']['resourceType']);
        $this->assertSame('', $variable['body']['resourceId']);

        $dateValidator = new DatetimeValidator();
        $this->assertSame(true, $dateValidator->isValid($variable['body']['$createdAt']));
        $this->assertSame(true, $dateValidator->isValid($variable['body']['$updatedAt']));

        // Verify via GET
        $get = $this->getVariable($variable['body']['$id']);
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame($variable['body']['$id'], $get['body']['$id']);
        $this->assertSame('APP_KEY', $get['body']['key']);

        // Verify via LIST
        $list = $this->listVariables(null, true);
        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $list['body']['total']);
        $this->assertGreaterThanOrEqual(1, \count($list['body']['variables']));

        // Cleanup
        $this->deleteVariable($variable['body']['$id']);
    }

    public function testCreateVariableNonSecret(): void
    {
        $variable = $this->createVariable(
            ID::unique(),
            'PUBLIC_KEY',
            'public-value',
            false
        );

        $this->assertSame(201, $variable['headers']['status-code']);
        $this->assertNotEmpty($variable['body']['$id']);
        $this->assertSame('PUBLIC_KEY', $variable['body']['key']);
        $this->assertSame(false, $variable['body']['secret']);
        $this->assertIsBool($variable['body']['secret']);
        $this->assertSame('public-value', $variable['body']['value']);

        // Cleanup
        $this->deleteVariable($variable['body']['$id']);
    }

    public function testCreateVariableSecretValueHidden(): void
    {
        $variable = $this->createVariable(
            ID::unique(),
            'SECRET_KEY',
            'hidden-value',
            true
        );

        $this->assertSame(201, $variable['headers']['status-code']);
        $this->assertSame(true, $variable['body']['secret']);
        $this->assertSame('', $variable['body']['value']);

        // Verify value is also hidden on GET
        $get = $this->getVariable($variable['body']['$id']);
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame('', $get['body']['value']);

        // Cleanup
        $this->deleteVariable($variable['body']['$id']);
    }

    public function testCreateVariableWithoutAuthentication(): void
    {
        $response = $this->createVariable(
            ID::unique(),
            'NO_AUTH_KEY',
            'no-auth-value',
            null,
            false
        );

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testCreateVariableInvalidId(): void
    {
        $variable = $this->createVariable(
            '!invalid-id!',
            'INVALID_ID_KEY',
            'value',
        );

        $this->assertSame(400, $variable['headers']['status-code']);
    }

    public function testCreateVariableMissingKey(): void
    {
        $response = $this->createVariable(
            ID::unique(),
            null,
            'some-value',
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testCreateVariableMissingValue(): void
    {
        $response = $this->createVariable(
            ID::unique(),
            'MISSING_VALUE_KEY',
            null,
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testCreateVariableDuplicateId(): void
    {
        $variableId = ID::unique();

        $variable = $this->createVariable(
            $variableId,
            'DUP_KEY_1',
            'value1',
        );

        $this->assertSame(201, $variable['headers']['status-code']);

        // Attempt to create with same ID
        $duplicate = $this->createVariable(
            $variableId,
            'DUP_KEY_2',
            'value2',
        );

        $this->assertSame(409, $duplicate['headers']['status-code']);
        $this->assertSame('variable_already_exists', $duplicate['body']['type']);

        // Cleanup
        $this->deleteVariable($variableId);
    }

    public function testCreateVariableCustomId(): void
    {
        $customId = 'my-custom-variable-id';

        $variable = $this->createVariable(
            $customId,
            'CUSTOM_ID_KEY',
            'custom-value',
        );

        $this->assertSame(201, $variable['headers']['status-code']);
        $this->assertSame($customId, $variable['body']['$id']);

        // Verify via GET
        $get = $this->getVariable($customId);
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame($customId, $get['body']['$id']);

        // Cleanup
        $this->deleteVariable($customId);
    }

    // Update variable tests

    public function testUpdateVariable(): void
    {
        $variable = $this->createVariable(
            ID::unique(),
            'ORIGINAL_KEY',
            'original-value',
            false
        );

        $this->assertSame(201, $variable['headers']['status-code']);
        $variableId = $variable['body']['$id'];

        // Update key and value
        $updated = $this->updateVariable($variableId, 'UPDATED_KEY', 'updated-value');

        $this->assertSame(200, $updated['headers']['status-code']);
        $this->assertSame($variableId, $updated['body']['$id']);
        $this->assertSame('UPDATED_KEY', $updated['body']['key']);
        $this->assertSame('updated-value', $updated['body']['value']);

        // Verify update persisted via GET
        $get = $this->getVariable($variableId);
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame('UPDATED_KEY', $get['body']['key']);
        $this->assertSame('updated-value', $get['body']['value']);

        // Cleanup
        $this->deleteVariable($variableId);
    }

    public function testUpdateVariableKey(): void
    {
        $variable = $this->createVariable(
            ID::unique(),
            'KEY_BEFORE',
            'unchanged-value',
            false
        );

        $this->assertSame(201, $variable['headers']['status-code']);
        $variableId = $variable['body']['$id'];

        // Update only key
        $updated = $this->updateVariable($variableId, 'KEY_AFTER');

        $this->assertSame(200, $updated['headers']['status-code']);
        $this->assertSame('KEY_AFTER', $updated['body']['key']);
        $this->assertSame('unchanged-value', $updated['body']['value']);

        // Cleanup
        $this->deleteVariable($variableId);
    }

    public function testUpdateVariableValue(): void
    {
        $variable = $this->createVariable(
            ID::unique(),
            'UNCHANGED_KEY',
            'value-before',
            false
        );

        $this->assertSame(201, $variable['headers']['status-code']);
        $variableId = $variable['body']['$id'];

        // Update only value
        $updated = $this->updateVariable($variableId, null, 'value-after');

        $this->assertSame(200, $updated['headers']['status-code']);
        $this->assertSame('UNCHANGED_KEY', $updated['body']['key']);
        $this->assertSame('value-after', $updated['body']['value']);

        // Cleanup
        $this->deleteVariable($variableId);
    }

    public function testUpdateVariableSetSecret(): void
    {
        $variable = $this->createVariable(
            ID::unique(),
            'MAKE_SECRET_KEY',
            'some-value',
            false
        );

        $this->assertSame(201, $variable['headers']['status-code']);
        $this->assertSame(false, $variable['body']['secret']);
        $variableId = $variable['body']['$id'];

        // Update to secret
        $updated = $this->updateVariable($variableId, null, null, true);

        $this->assertSame(200, $updated['headers']['status-code']);
        $this->assertSame(true, $updated['body']['secret']);
        $this->assertSame('', $updated['body']['value']);

        // Cleanup
        $this->deleteVariable($variableId);
    }

    public function testUpdateVariableCannotUnsetSecret(): void
    {
        $variable = $this->createVariable(
            ID::unique(),
            'UNSET_SECRET_KEY',
            'secret-value',
            true
        );

        $this->assertSame(201, $variable['headers']['status-code']);
        $variableId = $variable['body']['$id'];

        // Attempt to unset secret
        $updated = $this->updateVariable($variableId, null, null, false);

        $this->assertSame(400, $updated['headers']['status-code']);
        $this->assertSame('variable_cannot_unset_secret', $updated['body']['type']);

        // Verify variable is unchanged
        $get = $this->getVariable($variableId);
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame(true, $get['body']['secret']);

        // Cleanup
        $this->deleteVariable($variableId);
    }

    public function testUpdateVariableNoOp(): void
    {
        $variable = $this->createVariable(
            ID::unique(),
            'NOOP_KEY',
            'noop-value',
            false
        );

        $this->assertSame(201, $variable['headers']['status-code']);
        $variableId = $variable['body']['$id'];

        // Update with no parameters should fail with 400
        $updated = $this->updateVariable($variableId);

        $this->assertSame(400, $updated['headers']['status-code']);

        // Cleanup
        $this->deleteVariable($variableId);
    }

    public function testUpdateVariableWithoutAuthentication(): void
    {
        $variable = $this->createVariable(
            ID::unique(),
            'AUTH_UPDATE_KEY',
            'auth-value',
        );

        $this->assertSame(201, $variable['headers']['status-code']);
        $variableId = $variable['body']['$id'];

        // Attempt update without authentication
        $response = $this->updateVariable($variableId, 'UPDATED_KEY', null, null, false);

        $this->assertSame(401, $response['headers']['status-code']);

        // Cleanup
        $this->deleteVariable($variableId);
    }

    public function testUpdateVariableNotFound(): void
    {
        $updated = $this->updateVariable('non-existent-id', 'NEW_KEY', 'new-value');

        $this->assertSame(404, $updated['headers']['status-code']);
        $this->assertSame('variable_not_found', $updated['body']['type']);
    }

    // Get variable tests

    public function testGetVariable(): void
    {
        $variable = $this->createVariable(
            ID::unique(),
            'GET_TEST_KEY',
            'get-test-value',
            false
        );

        $this->assertSame(201, $variable['headers']['status-code']);
        $variableId = $variable['body']['$id'];

        $get = $this->getVariable($variableId);

        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame($variableId, $get['body']['$id']);
        $this->assertSame('GET_TEST_KEY', $get['body']['key']);
        $this->assertSame('get-test-value', $get['body']['value']);
        $this->assertSame(false, $get['body']['secret']);
        $this->assertSame('project', $get['body']['resourceType']);
        $this->assertSame('', $get['body']['resourceId']);

        $dateValidator = new DatetimeValidator();
        $this->assertSame(true, $dateValidator->isValid($get['body']['$createdAt']));
        $this->assertSame(true, $dateValidator->isValid($get['body']['$updatedAt']));

        // Cleanup
        $this->deleteVariable($variableId);
    }

    public function testGetVariableNotFound(): void
    {
        $get = $this->getVariable('non-existent-id');

        $this->assertSame(404, $get['headers']['status-code']);
        $this->assertSame('variable_not_found', $get['body']['type']);
    }

    public function testGetVariableWithoutAuthentication(): void
    {
        $variable = $this->createVariable(
            ID::unique(),
            'AUTH_GET_KEY',
            'auth-get-value',
        );

        $this->assertSame(201, $variable['headers']['status-code']);
        $variableId = $variable['body']['$id'];

        // Attempt GET without authentication
        $response = $this->getVariable($variableId, false);

        $this->assertSame(401, $response['headers']['status-code']);

        // Cleanup
        $this->deleteVariable($variableId);
    }

    // List variables tests

    public function testListVariables(): void
    {
        // Create multiple variables
        $variable1 = $this->createVariable(
            ID::unique(),
            'LIST_KEY_ALPHA',
            'alpha-value',
            false
        );
        $this->assertSame(201, $variable1['headers']['status-code']);

        $variable2 = $this->createVariable(
            ID::unique(),
            'LIST_KEY_BETA',
            'beta-value',
            true
        );
        $this->assertSame(201, $variable2['headers']['status-code']);

        $variable3 = $this->createVariable(
            ID::unique(),
            'LIST_KEY_GAMMA',
            'gamma-value',
            false
        );
        $this->assertSame(201, $variable3['headers']['status-code']);

        // List all
        $list = $this->listVariables(null, true);

        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertGreaterThanOrEqual(3, $list['body']['total']);
        $this->assertGreaterThanOrEqual(3, \count($list['body']['variables']));
        $this->assertIsArray($list['body']['variables']);

        // Verify structure of returned variables
        foreach ($list['body']['variables'] as $variable) {
            $this->assertArrayHasKey('$id', $variable);
            $this->assertArrayHasKey('$createdAt', $variable);
            $this->assertArrayHasKey('$updatedAt', $variable);
            $this->assertArrayHasKey('key', $variable);
            $this->assertArrayHasKey('value', $variable);
            $this->assertArrayHasKey('secret', $variable);
            $this->assertArrayHasKey('resourceType', $variable);
            $this->assertArrayHasKey('resourceId', $variable);
        }

        // Cleanup
        $this->deleteVariable($variable1['body']['$id']);
        $this->deleteVariable($variable2['body']['$id']);
        $this->deleteVariable($variable3['body']['$id']);
    }

    public function testListVariablesWithLimit(): void
    {
        $variable1 = $this->createVariable(
            ID::unique(),
            'LIMIT_KEY_1',
            'limit-value-1',
        );
        $this->assertSame(201, $variable1['headers']['status-code']);

        $variable2 = $this->createVariable(
            ID::unique(),
            'LIMIT_KEY_2',
            'limit-value-2',
        );
        $this->assertSame(201, $variable2['headers']['status-code']);

        // List with limit of 1
        $list = $this->listVariables([
            Query::limit(1)->toString(),
        ], true);

        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertCount(1, $list['body']['variables']);
        $this->assertGreaterThanOrEqual(2, $list['body']['total']);

        // Cleanup
        $this->deleteVariable($variable1['body']['$id']);
        $this->deleteVariable($variable2['body']['$id']);
    }

    public function testListVariablesWithOffset(): void
    {
        $variable1 = $this->createVariable(
            ID::unique(),
            'OFFSET_KEY_1',
            'offset-value-1',
        );
        $this->assertSame(201, $variable1['headers']['status-code']);

        $variable2 = $this->createVariable(
            ID::unique(),
            'OFFSET_KEY_2',
            'offset-value-2',
        );
        $this->assertSame(201, $variable2['headers']['status-code']);

        // List all to get total
        $listAll = $this->listVariables(null, true);
        $this->assertSame(200, $listAll['headers']['status-code']);
        $totalAll = \count($listAll['body']['variables']);

        // List with offset
        $listOffset = $this->listVariables([
            Query::offset(1)->toString(),
        ], true);

        $this->assertSame(200, $listOffset['headers']['status-code']);
        $this->assertCount($totalAll - 1, $listOffset['body']['variables']);

        // Cleanup
        $this->deleteVariable($variable1['body']['$id']);
        $this->deleteVariable($variable2['body']['$id']);
    }

    public function testListVariablesWithoutTotal(): void
    {
        $variable = $this->createVariable(
            ID::unique(),
            'NO_TOTAL_KEY',
            'no-total-value',
        );
        $this->assertSame(201, $variable['headers']['status-code']);

        // List with total=false
        $list = $this->listVariables(null, false);

        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertSame(0, $list['body']['total']);
        $this->assertGreaterThanOrEqual(1, \count($list['body']['variables']));

        // Cleanup
        $this->deleteVariable($variable['body']['$id']);
    }

    public function testListVariablesCursorPagination(): void
    {
        $variable1 = $this->createVariable(
            ID::unique(),
            'CURSOR_KEY_1',
            'cursor-value-1',
        );
        $this->assertSame(201, $variable1['headers']['status-code']);

        $variable2 = $this->createVariable(
            ID::unique(),
            'CURSOR_KEY_2',
            'cursor-value-2',
        );
        $this->assertSame(201, $variable2['headers']['status-code']);

        // Get first page with limit 1
        $page1 = $this->listVariables([
            Query::limit(1)->toString(),
        ], true);

        $this->assertSame(200, $page1['headers']['status-code']);
        $this->assertCount(1, $page1['body']['variables']);
        $cursorId = $page1['body']['variables'][0]['$id'];

        // Get next page using cursor
        $page2 = $this->listVariables([
            Query::limit(1)->toString(),
            Query::cursorAfter(new Document(['$id' => $cursorId]))->toString(),
        ], true);

        $this->assertSame(200, $page2['headers']['status-code']);
        $this->assertCount(1, $page2['body']['variables']);
        $this->assertNotEquals($cursorId, $page2['body']['variables'][0]['$id']);

        // Cleanup
        $this->deleteVariable($variable1['body']['$id']);
        $this->deleteVariable($variable2['body']['$id']);
    }

    public function testListVariablesWithoutAuthentication(): void
    {
        $response = $this->listVariables(null, null, false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testListVariablesInvalidCursor(): void
    {
        $list = $this->listVariables([
            Query::cursorAfter(new Document(['$id' => 'non-existent-id']))->toString(),
        ], true);

        $this->assertSame(400, $list['headers']['status-code']);
    }

    // Delete variable tests

    public function testDeleteVariable(): void
    {
        $variable = $this->createVariable(
            ID::unique(),
            'DELETE_KEY',
            'delete-value',
        );

        $this->assertSame(201, $variable['headers']['status-code']);
        $variableId = $variable['body']['$id'];

        // Verify it exists
        $get = $this->getVariable($variableId);
        $this->assertSame(200, $get['headers']['status-code']);

        // Delete
        $delete = $this->deleteVariable($variableId);
        $this->assertSame(204, $delete['headers']['status-code']);
        $this->assertEmpty($delete['body']);

        // Verify it no longer exists
        $get = $this->getVariable($variableId);
        $this->assertSame(404, $get['headers']['status-code']);
        $this->assertSame('variable_not_found', $get['body']['type']);
    }

    public function testDeleteVariableNotFound(): void
    {
        $delete = $this->deleteVariable('non-existent-id');

        $this->assertSame(404, $delete['headers']['status-code']);
        $this->assertSame('variable_not_found', $delete['body']['type']);
    }

    public function testDeleteVariableWithoutAuthentication(): void
    {
        $variable = $this->createVariable(
            ID::unique(),
            'DELETE_AUTH_KEY',
            'delete-auth-value',
        );

        $this->assertSame(201, $variable['headers']['status-code']);
        $variableId = $variable['body']['$id'];

        // Attempt DELETE without authentication
        $response = $this->deleteVariable($variableId, false);

        $this->assertSame(401, $response['headers']['status-code']);

        // Verify it still exists
        $get = $this->getVariable($variableId);
        $this->assertSame(200, $get['headers']['status-code']);

        // Cleanup
        $this->deleteVariable($variableId);
    }

    public function testDeleteVariableRemovedFromList(): void
    {
        $variable = $this->createVariable(
            ID::unique(),
            'DELETE_LIST_KEY',
            'delete-list-value',
        );

        $this->assertSame(201, $variable['headers']['status-code']);
        $variableId = $variable['body']['$id'];

        // Get list count before delete
        $listBefore = $this->listVariables(null, true);
        $this->assertSame(200, $listBefore['headers']['status-code']);
        $countBefore = $listBefore['body']['total'];

        // Delete
        $delete = $this->deleteVariable($variableId);
        $this->assertSame(204, $delete['headers']['status-code']);

        // Get list count after delete
        $listAfter = $this->listVariables(null, true);
        $this->assertSame(200, $listAfter['headers']['status-code']);
        $this->assertSame($countBefore - 1, $listAfter['body']['total']);

        // Verify the deleted variable is not in the list
        $ids = \array_column($listAfter['body']['variables'], '$id');
        $this->assertNotContains($variableId, $ids);
    }

    public function testDeleteVariableDoubleDelete(): void
    {
        $variable = $this->createVariable(
            ID::unique(),
            'DOUBLE_DELETE_KEY',
            'double-delete-value',
        );

        $this->assertSame(201, $variable['headers']['status-code']);
        $variableId = $variable['body']['$id'];

        // First delete succeeds
        $delete = $this->deleteVariable($variableId);
        $this->assertSame(204, $delete['headers']['status-code']);

        // Second delete returns 404
        $delete = $this->deleteVariable($variableId);
        $this->assertSame(404, $delete['headers']['status-code']);
        $this->assertSame('variable_not_found', $delete['body']['type']);
    }

    // Integration tests

    /**
     * Test that project variables are available in function build and runtime.
     */
    public function testProjectVariableInFunction(): void
    {
        $projectId = $this->getProject()['$id'];
        $apiKey = $this->getProject()['apiKey'];

        // 1. Create a project variable
        $variable = $this->createVariable(
            ID::unique(),
            'GLOBAL_VARIABLE',
            'Project Variable Value',
            false
        );

        $this->assertSame(201, $variable['headers']['status-code']);
        $variableId = $variable['body']['$id'];

        // 2. Create a function with build commands that echo the variable
        $function = $this->client->call(Client::METHOD_POST, '/functions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
        ], [
            'functionId' => ID::unique(),
            'name' => 'Project Variable Test',
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
            'execute' => ['any'],
            'timeout' => 15,
            'commands' => 'echo $GLOBAL_VARIABLE',
        ]);

        $this->assertSame(201, $function['headers']['status-code']);
        $functionId = $function['body']['$id'];

        // 3. Deploy the function (basic function reads GLOBAL_VARIABLE from env)
        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/deployments', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
        ], [
            'code' => $this->packageCode('functions', 'basic'),
            'activate' => true,
        ]);

        $this->assertSame(202, $deployment['headers']['status-code']);
        $deploymentId = $deployment['body']['$id'] ?? '';

        // 4. Wait for deployment to be ready and activated
        $this->assertEventually(function () use ($projectId, $apiKey, $functionId, $deploymentId) {
            $deployment = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/deployments/' . $deploymentId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $apiKey,
            ]);

            $status = $deployment['body']['status'] ?? '';
            if ($status === 'failed') {
                throw new Critical('Deployment build failed: ' . ($deployment['body']['buildLogs'] ?? 'no logs'));
            }

            $this->assertSame('ready', $status, 'Deployment status is not ready');
        }, 120000, 500);

        $this->assertEventually(function () use ($projectId, $apiKey, $functionId, $deploymentId) {
            $function = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $apiKey,
            ]);
            $this->assertSame($deploymentId, $function['body']['deploymentId'] ?? '');
        }, 120000, 500);

        // 5. Verify the project variable was available during build
        $deployment = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/deployments/' . $deploymentId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
        ]);
        $this->assertSame(200, $deployment['headers']['status-code']);
        $this->assertStringContainsString('Project Variable Value', $deployment['body']['buildLogs']);

        // 6. Execute the function and verify the project variable is in runtime output
        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'async' => false,
        ]);

        $this->assertSame(201, $execution['headers']['status-code']);
        $this->assertSame('completed', $execution['body']['status']);
        $this->assertSame(200, $execution['body']['responseStatusCode']);
        $output = json_decode($execution['body']['responseBody'], true);
        $this->assertSame('Project Variable Value', $output['GLOBAL_VARIABLE']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/functions/' . $functionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
        ]);
        $this->deleteVariable($variableId);
    }

    /**
     * Test that project variables are available in site build and SSR runtime.
     */
    public function testProjectVariableInSite(): void
    {
        $projectId = $this->getProject()['$id'];
        $apiKey = $this->getProject()['apiKey'];

        // 1. Create a project variable
        $variable = $this->createVariable(
            ID::unique(),
            'name',
            'ProjectVarTest',
        );

        $this->assertSame(201, $variable['headers']['status-code']);
        $variableId = $variable['body']['$id'];

        // 2. Create a site
        $site = $this->client->call(Client::METHOD_POST, '/sites', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
        ], [
            'siteId' => ID::unique(),
            'name' => 'Project Variable Astro Site',
            'framework' => 'astro',
            'adapter' => 'ssr',
            'buildRuntime' => 'node-22',
            'outputDirectory' => './dist',
            'buildCommand' => 'echo $name && npm run build',
            'installCommand' => 'npm ci',
            'fallbackFile' => '',
        ]);

        $this->assertSame(201, $site['headers']['status-code']);
        $siteId = $site['body']['$id'];

        // 3. Setup domain for proxy access
        $sitesDomain = \explode(',', System::getEnv('_APP_DOMAIN_SITES', ''))[0];
        $rule = $this->client->call(Client::METHOD_POST, '/proxy/rules/site', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'domain' => ID::unique() . '.' . $sitesDomain,
            'siteId' => $siteId,
        ]);

        $this->assertSame(201, $rule['headers']['status-code']);

        // 4. Deploy the site (astro site reads import.meta.env.name)
        $deployment = $this->client->call(Client::METHOD_POST, '/sites/' . $siteId . '/deployments', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
        ], [
            'code' => $this->packageCode('sites', 'astro'),
            'activate' => 'true',
        ]);

        $this->assertSame(202, $deployment['headers']['status-code']);
        $deploymentId = $deployment['body']['$id'] ?? '';

        // 5. Wait for deployment to be ready and activated
        $this->assertEventually(function () use ($projectId, $apiKey, $siteId, $deploymentId) {
            $deployment = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId . '/deployments/' . $deploymentId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $apiKey,
            ]);

            $status = $deployment['body']['status'] ?? '';
            if ($status === 'failed') {
                throw new Critical('Site deployment failed: ' . json_encode($deployment['body'], JSON_PRETTY_PRINT));
            }

            $this->assertSame('ready', $status, 'Deployment status is not ready');
        }, 120000, 500);

        $this->assertEventually(function () use ($projectId, $apiKey, $siteId, $deploymentId) {
            $site = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $apiKey,
            ]);
            $this->assertSame($deploymentId, $site['body']['deploymentId'] ?? '');
        }, 120000, 500);

        // 6. Verify the project variable was available during build
        $deployment = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId . '/deployments/' . $deploymentId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
        ]);
        $this->assertSame(200, $deployment['headers']['status-code']);
        $this->assertStringContainsString('ProjectVarTest', $deployment['body']['buildLogs']);

        // 7. Get the domain and access the site
        $rules = $this->client->call(Client::METHOD_GET, '/proxy/rules', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('deploymentResourceId', [$siteId])->toString(),
                Query::equal('trigger', ['manual'])->toString(),
                Query::equal('type', ['deployment'])->toString(),
            ],
        ]);

        $this->assertSame(200, $rules['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, \count($rules['body']['rules']));
        $domain = $rules['body']['rules'][0]['domain'];

        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $domain);

        $response = $proxyClient->call(Client::METHOD_GET, '/');

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertStringContainsString('Env variable is ProjectVarTest', $response['body']);
        $this->assertStringNotContainsString('Variable not found', $response['body']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/sites/' . $siteId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
        ]);
        $this->deleteVariable($variableId);
    }

    // Helpers

    protected function createVariable(string $variableId, ?string $key, ?string $value, ?bool $secret = null, bool $authenticated = true): mixed
    {
        $params = [
            'variableId' => $variableId,
        ];

        if ($key !== null) {
            $params['key'] = $key;
        }

        if ($value !== null) {
            $params['value'] = $value;
        }

        if ($secret !== null) {
            $params['secret'] = $secret;
        }

        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_POST, '/project/variables', $headers, $params);
    }

    protected function updateVariable(string $variableId, ?string $key = null, ?string $value = null, ?bool $secret = null, bool $authenticated = true): mixed
    {
        $params = [];

        if ($key !== null) {
            $params['key'] = $key;
        }

        if ($value !== null) {
            $params['value'] = $value;
        }

        if ($secret !== null) {
            $params['secret'] = $secret;
        }

        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_PUT, '/project/variables/' . $variableId, $headers, $params);
    }

    protected function getVariable(string $variableId, bool $authenticated = true): mixed
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_GET, '/project/variables/' . $variableId, $headers);
    }

    /**
     * @param array<string>|null $queries
     */
    protected function listVariables(?array $queries, ?bool $total, bool $authenticated = true): mixed
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_GET, '/project/variables', $headers, [
            'queries' => $queries,
            'total' => $total,
        ]);
    }

    protected function deleteVariable(string $variableId, bool $authenticated = true): mixed
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_DELETE, '/project/variables/' . $variableId, $headers);
    }

    protected function packageCode(string $type, string $name): CURLFile
    {
        $folderPath = realpath(__DIR__ . '/../../../resources/' . $type) . "/$name";
        $tarPath = "$folderPath/code.tar.gz";

        Console::execute("cd $folderPath && tar --exclude code.tar.gz --exclude node_modules -czf code.tar.gz .", '', $this->stdout, $this->stderr);

        if (filesize($tarPath) > 1024 * 1024 * 5) {
            throw new \Exception('Code package is too large. Use the chunked upload method instead.');
        }

        return new CURLFile($tarPath, 'application/x-gzip', \basename($tarPath));
    }
}
