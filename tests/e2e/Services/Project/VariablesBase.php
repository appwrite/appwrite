<?php

namespace Tests\E2E\Services\Project;

use Appwrite\Tests\Async;
use Tests\E2E\Client;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Datetime as DatetimeValidator;

trait VariablesBase
{
    use Async;

    // Create variable tests

    public function testCreateVariable(): void
    {
        $variable = $this->createVariable(
            ID::unique(),
            'APP_KEY',
            'my-secret-value',
            null
        );

        $this->assertEquals(201, $variable['headers']['status-code']);
        $this->assertNotEmpty($variable['body']['$id']);
        $this->assertEquals('APP_KEY', $variable['body']['key']);
        $this->assertEquals(true, $variable['body']['secret']);
        $this->assertNull($variable['body']['value']);
        $this->assertEquals('project', $variable['body']['resourceType']);
        $this->assertEquals('', $variable['body']['resourceId']);

        $dateValidator = new DatetimeValidator();
        $this->assertEquals(true, $dateValidator->isValid($variable['body']['$createdAt']));
        $this->assertEquals(true, $dateValidator->isValid($variable['body']['$updatedAt']));

        // Verify via GET
        $get = $this->getVariable($variable['body']['$id']);
        $this->assertEquals(200, $get['headers']['status-code']);
        $this->assertEquals($variable['body']['$id'], $get['body']['$id']);
        $this->assertEquals('APP_KEY', $get['body']['key']);

        // Verify via LIST
        $list = $this->listVariables(null, true);
        $this->assertEquals(200, $list['headers']['status-code']);
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

        $this->assertEquals(201, $variable['headers']['status-code']);
        $this->assertNotEmpty($variable['body']['$id']);
        $this->assertEquals('PUBLIC_KEY', $variable['body']['key']);
        $this->assertEquals(false, $variable['body']['secret']);
        $this->assertIsBool($variable['body']['secret']);
        $this->assertEquals('public-value', $variable['body']['value']);

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

        $this->assertEquals(201, $variable['headers']['status-code']);
        $this->assertEquals(true, $variable['body']['secret']);
        $this->assertNull($variable['body']['value']);

        // Verify value is also hidden on GET
        $get = $this->getVariable($variable['body']['$id']);
        $this->assertEquals(200, $get['headers']['status-code']);
        $this->assertNull($get['body']['value']);

        // Cleanup
        $this->deleteVariable($variable['body']['$id']);
    }

    public function testCreateVariableWithoutAuthentication(): void
    {
        $response = $this->client->call(Client::METHOD_POST, '/project/variables', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'variableId' => ID::unique(),
            'key' => 'NO_AUTH_KEY',
            'value' => 'no-auth-value',
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);
    }

    public function testCreateVariableInvalidId(): void
    {
        $variable = $this->createVariable(
            '!invalid-id!',
            'INVALID_ID_KEY',
            'value',
            null
        );

        $this->assertEquals(400, $variable['headers']['status-code']);
    }

    public function testCreateVariableMissingKey(): void
    {
        $response = $this->client->call(Client::METHOD_POST, '/project/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'variableId' => ID::unique(),
            'value' => 'some-value',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testCreateVariableMissingValue(): void
    {
        $response = $this->client->call(Client::METHOD_POST, '/project/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'variableId' => ID::unique(),
            'key' => 'MISSING_VALUE_KEY',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testCreateVariableDuplicateId(): void
    {
        $variableId = ID::unique();

        $variable = $this->createVariable(
            $variableId,
            'DUP_KEY_1',
            'value1',
            null
        );

        $this->assertEquals(201, $variable['headers']['status-code']);

        // Attempt to create with same ID
        $duplicate = $this->createVariable(
            $variableId,
            'DUP_KEY_2',
            'value2',
            null
        );

        $this->assertEquals(409, $duplicate['headers']['status-code']);
        $this->assertEquals('variable_already_exists', $duplicate['body']['type']);

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
            null
        );

        $this->assertEquals(201, $variable['headers']['status-code']);
        $this->assertEquals($customId, $variable['body']['$id']);

        // Verify via GET
        $get = $this->getVariable($customId);
        $this->assertEquals(200, $get['headers']['status-code']);
        $this->assertEquals($customId, $get['body']['$id']);

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

        $this->assertEquals(201, $variable['headers']['status-code']);
        $variableId = $variable['body']['$id'];

        // Update key and value
        $updated = $this->updateVariable($variableId, 'UPDATED_KEY', 'updated-value', null);

        $this->assertEquals(200, $updated['headers']['status-code']);
        $this->assertEquals($variableId, $updated['body']['$id']);
        $this->assertEquals('UPDATED_KEY', $updated['body']['key']);
        $this->assertEquals('updated-value', $updated['body']['value']);

        // Verify update persisted via GET
        $get = $this->getVariable($variableId);
        $this->assertEquals(200, $get['headers']['status-code']);
        $this->assertEquals('UPDATED_KEY', $get['body']['key']);
        $this->assertEquals('updated-value', $get['body']['value']);

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

        $this->assertEquals(201, $variable['headers']['status-code']);
        $variableId = $variable['body']['$id'];

        // Update only key
        $updated = $this->updateVariable($variableId, 'KEY_AFTER', null, null);

        $this->assertEquals(200, $updated['headers']['status-code']);
        $this->assertEquals('KEY_AFTER', $updated['body']['key']);
        $this->assertEquals('unchanged-value', $updated['body']['value']);

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

        $this->assertEquals(201, $variable['headers']['status-code']);
        $variableId = $variable['body']['$id'];

        // Update only value
        $updated = $this->updateVariable($variableId, null, 'value-after', null);

        $this->assertEquals(200, $updated['headers']['status-code']);
        $this->assertEquals('UNCHANGED_KEY', $updated['body']['key']);
        $this->assertEquals('value-after', $updated['body']['value']);

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

        $this->assertEquals(201, $variable['headers']['status-code']);
        $this->assertEquals(false, $variable['body']['secret']);
        $variableId = $variable['body']['$id'];

        // Update to secret
        $updated = $this->updateVariable($variableId, null, null, true);

        $this->assertEquals(200, $updated['headers']['status-code']);
        $this->assertEquals(true, $updated['body']['secret']);
        $this->assertNull($updated['body']['value']);

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

        $this->assertEquals(201, $variable['headers']['status-code']);
        $variableId = $variable['body']['$id'];

        // Attempt to unset secret
        $updated = $this->updateVariable($variableId, null, null, false);

        $this->assertEquals(400, $updated['headers']['status-code']);
        $this->assertEquals('variable_cannot_unset_secret', $updated['body']['type']);

        // Verify variable is unchanged
        $get = $this->getVariable($variableId);
        $this->assertEquals(200, $get['headers']['status-code']);
        $this->assertEquals(true, $get['body']['secret']);

        // Cleanup
        $this->deleteVariable($variableId);
    }

    public function testUpdateVariableWithoutAuthentication(): void
    {
        $variable = $this->createVariable(
            ID::unique(),
            'AUTH_UPDATE_KEY',
            'auth-value',
            null
        );

        $this->assertEquals(201, $variable['headers']['status-code']);
        $variableId = $variable['body']['$id'];

        // Attempt update without authentication
        $response = $this->client->call(Client::METHOD_PUT, '/project/variables/' . $variableId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'key' => 'UPDATED_KEY',
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        // Cleanup
        $this->deleteVariable($variableId);
    }

    public function testUpdateVariableNotFound(): void
    {
        $updated = $this->updateVariable('non-existent-id', 'NEW_KEY', 'new-value', null);

        $this->assertEquals(404, $updated['headers']['status-code']);
        $this->assertEquals('variable_not_found', $updated['body']['type']);
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

        $this->assertEquals(201, $variable['headers']['status-code']);
        $variableId = $variable['body']['$id'];

        $get = $this->getVariable($variableId);

        $this->assertEquals(200, $get['headers']['status-code']);
        $this->assertEquals($variableId, $get['body']['$id']);
        $this->assertEquals('GET_TEST_KEY', $get['body']['key']);
        $this->assertEquals('get-test-value', $get['body']['value']);
        $this->assertEquals(false, $get['body']['secret']);
        $this->assertEquals('project', $get['body']['resourceType']);
        $this->assertEquals('', $get['body']['resourceId']);

        $dateValidator = new DatetimeValidator();
        $this->assertEquals(true, $dateValidator->isValid($get['body']['$createdAt']));
        $this->assertEquals(true, $dateValidator->isValid($get['body']['$updatedAt']));

        // Cleanup
        $this->deleteVariable($variableId);
    }

    public function testGetVariableNotFound(): void
    {
        $get = $this->getVariable('non-existent-id');

        $this->assertEquals(404, $get['headers']['status-code']);
        $this->assertEquals('variable_not_found', $get['body']['type']);
    }

    public function testGetVariableWithoutAuthentication(): void
    {
        $variable = $this->createVariable(
            ID::unique(),
            'AUTH_GET_KEY',
            'auth-get-value',
            null
        );

        $this->assertEquals(201, $variable['headers']['status-code']);
        $variableId = $variable['body']['$id'];

        // Attempt GET without authentication
        $response = $this->client->call(Client::METHOD_GET, '/project/variables/' . $variableId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

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
        $this->assertEquals(201, $variable1['headers']['status-code']);

        $variable2 = $this->createVariable(
            ID::unique(),
            'LIST_KEY_BETA',
            'beta-value',
            true
        );
        $this->assertEquals(201, $variable2['headers']['status-code']);

        $variable3 = $this->createVariable(
            ID::unique(),
            'LIST_KEY_GAMMA',
            'gamma-value',
            false
        );
        $this->assertEquals(201, $variable3['headers']['status-code']);

        // List all
        $list = $this->listVariables(null, true);

        $this->assertEquals(200, $list['headers']['status-code']);
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
            null
        );
        $this->assertEquals(201, $variable1['headers']['status-code']);

        $variable2 = $this->createVariable(
            ID::unique(),
            'LIMIT_KEY_2',
            'limit-value-2',
            null
        );
        $this->assertEquals(201, $variable2['headers']['status-code']);

        // List with limit of 1
        $list = $this->listVariables([
            Query::limit(1)->toString(),
        ], true);

        $this->assertEquals(200, $list['headers']['status-code']);
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
            null
        );
        $this->assertEquals(201, $variable1['headers']['status-code']);

        $variable2 = $this->createVariable(
            ID::unique(),
            'OFFSET_KEY_2',
            'offset-value-2',
            null
        );
        $this->assertEquals(201, $variable2['headers']['status-code']);

        // List all to get total
        $listAll = $this->listVariables(null, true);
        $this->assertEquals(200, $listAll['headers']['status-code']);
        $totalAll = \count($listAll['body']['variables']);

        // List with offset
        $listOffset = $this->listVariables([
            Query::offset(1)->toString(),
        ], true);

        $this->assertEquals(200, $listOffset['headers']['status-code']);
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
            null
        );
        $this->assertEquals(201, $variable['headers']['status-code']);

        // List with total=false
        $list = $this->listVariables(null, false);

        $this->assertEquals(200, $list['headers']['status-code']);
        $this->assertEquals(0, $list['body']['total']);
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
            null
        );
        $this->assertEquals(201, $variable1['headers']['status-code']);

        $variable2 = $this->createVariable(
            ID::unique(),
            'CURSOR_KEY_2',
            'cursor-value-2',
            null
        );
        $this->assertEquals(201, $variable2['headers']['status-code']);

        // Get first page with limit 1
        $page1 = $this->listVariables([
            Query::limit(1)->toString(),
        ], true);

        $this->assertEquals(200, $page1['headers']['status-code']);
        $this->assertCount(1, $page1['body']['variables']);
        $cursorId = $page1['body']['variables'][0]['$id'];

        // Get next page using cursor
        $page2 = $this->listVariables([
            Query::limit(1)->toString(),
            Query::cursorAfter(new Document(['$id' => $cursorId]))->toString(),
        ], true);

        $this->assertEquals(200, $page2['headers']['status-code']);
        $this->assertCount(1, $page2['body']['variables']);
        $this->assertNotEquals($cursorId, $page2['body']['variables'][0]['$id']);

        // Cleanup
        $this->deleteVariable($variable1['body']['$id']);
        $this->deleteVariable($variable2['body']['$id']);
    }

    public function testListVariablesWithoutAuthentication(): void
    {
        $response = $this->client->call(Client::METHOD_GET, '/project/variables', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);
    }

    public function testListVariablesInvalidCursor(): void
    {
        $list = $this->listVariables([
            Query::cursorAfter(new Document(['$id' => 'non-existent-id']))->toString(),
        ], true);

        $this->assertEquals(400, $list['headers']['status-code']);
    }

    // Delete variable tests

    public function testDeleteVariable(): void
    {
        $variable = $this->createVariable(
            ID::unique(),
            'DELETE_KEY',
            'delete-value',
            null
        );

        $this->assertEquals(201, $variable['headers']['status-code']);
        $variableId = $variable['body']['$id'];

        // Verify it exists
        $get = $this->getVariable($variableId);
        $this->assertEquals(200, $get['headers']['status-code']);

        // Delete
        $delete = $this->deleteVariable($variableId);
        $this->assertEquals(204, $delete['headers']['status-code']);
        $this->assertEmpty($delete['body']);

        // Verify it no longer exists
        $get = $this->getVariable($variableId);
        $this->assertEquals(404, $get['headers']['status-code']);
        $this->assertEquals('variable_not_found', $get['body']['type']);
    }

    public function testDeleteVariableNotFound(): void
    {
        $delete = $this->deleteVariable('non-existent-id');

        $this->assertEquals(404, $delete['headers']['status-code']);
        $this->assertEquals('variable_not_found', $delete['body']['type']);
    }

    public function testDeleteVariableWithoutAuthentication(): void
    {
        $variable = $this->createVariable(
            ID::unique(),
            'DELETE_AUTH_KEY',
            'delete-auth-value',
            null
        );

        $this->assertEquals(201, $variable['headers']['status-code']);
        $variableId = $variable['body']['$id'];

        // Attempt DELETE without authentication
        $response = $this->client->call(Client::METHOD_DELETE, '/project/variables/' . $variableId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        // Verify it still exists
        $get = $this->getVariable($variableId);
        $this->assertEquals(200, $get['headers']['status-code']);

        // Cleanup
        $this->deleteVariable($variableId);
    }

    public function testDeleteVariableRemovedFromList(): void
    {
        $variable = $this->createVariable(
            ID::unique(),
            'DELETE_LIST_KEY',
            'delete-list-value',
            null
        );

        $this->assertEquals(201, $variable['headers']['status-code']);
        $variableId = $variable['body']['$id'];

        // Get list count before delete
        $listBefore = $this->listVariables(null, true);
        $this->assertEquals(200, $listBefore['headers']['status-code']);
        $countBefore = $listBefore['body']['total'];

        // Delete
        $delete = $this->deleteVariable($variableId);
        $this->assertEquals(204, $delete['headers']['status-code']);

        // Get list count after delete
        $listAfter = $this->listVariables(null, true);
        $this->assertEquals(200, $listAfter['headers']['status-code']);
        $this->assertEquals($countBefore - 1, $listAfter['body']['total']);

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
            null
        );

        $this->assertEquals(201, $variable['headers']['status-code']);
        $variableId = $variable['body']['$id'];

        // First delete succeeds
        $delete = $this->deleteVariable($variableId);
        $this->assertEquals(204, $delete['headers']['status-code']);

        // Second delete returns 404
        $delete = $this->deleteVariable($variableId);
        $this->assertEquals(404, $delete['headers']['status-code']);
        $this->assertEquals('variable_not_found', $delete['body']['type']);
    }

    // Helpers

    /**
     * @param array<string>|null $queries
     */
    protected function listVariables(?array $queries, ?bool $total): mixed
    {
        $variables = $this->client->call(Client::METHOD_GET, '/project/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => $queries,
            'total' => $total
        ]);

        return $variables;
    }

    protected function getVariable(string $variableId): mixed
    {
        $variable = $this->client->call(Client::METHOD_GET, '/project/variables/' . $variableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $variable;
    }

    protected function createVariable(string $variableId, string $key, string $value, ?bool $secret): mixed
    {
        $params = [
            'variableId' => $variableId,
            'key' => $key,
            'value' => $value,
        ];

        if ($secret !== null) {
            $params['secret'] = $secret;
        }

        $variable = $this->client->call(Client::METHOD_POST, '/project/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $variable;
    }

    protected function updateVariable(string $variableId, ?string $key, ?string $value, ?bool $secret): mixed
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

        $variable = $this->client->call(Client::METHOD_PUT, '/project/variables/' . $variableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $variable;
    }

    protected function deleteVariable(string $variableId): mixed
    {
        $variable = $this->client->call(Client::METHOD_DELETE, '/project/variables/' . $variableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $variable;
    }
}
