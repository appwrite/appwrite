<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;

class PoliciesPasswordDictionaryIntegrationTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    public function testPasswordDictionaryIntegration(): void
    {
        $projectId = $this->getProject()['$id'];
        $apiKey = $this->getProject()['apiKey'];

        $serverHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
            'x-appwrite-response-format' => '1.9.4',
        ];

        // "password" is the top entry in the common-passwords dictionary and is 8 chars (min length).
        $commonPassword = 'football';

        // Step 1: Disable password dictionary policy
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/password-dictionary', $serverHeaders, [
            'enabled' => false,
        ]);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertFalse($response['body']['authPasswordDictionary']);

        // Step 2: Create user with common password - should succeed
        $user1 = $this->client->call(Client::METHOD_POST, '/users', $serverHeaders, [
            'userId' => ID::unique(),
            'email' => 'dict_off_' . uniqid() . '@localhost.test',
            'password' => $commonPassword,
            'name' => 'Dictionary Off User',
        ]);
        $this->assertSame(201, $user1['headers']['status-code']);
        $this->assertNotEmpty($user1['body']['$id']);

        // Step 3: Enable password dictionary policy
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/password-dictionary', $serverHeaders, [
            'enabled' => true,
        ]);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertTrue($response['body']['authPasswordDictionary']);

        // Step 4: Creating another user with the common password must fail
        $user2 = $this->client->call(Client::METHOD_POST, '/users', $serverHeaders, [
            'userId' => ID::unique(),
            'email' => 'dict_on_' . uniqid() . '@localhost.test',
            'password' => $commonPassword,
            'name' => 'Dictionary On User',
        ]);
        $this->assertSame(400, $user2['headers']['status-code']);

        // Cleanup: disable policy
        $this->client->call(Client::METHOD_PATCH, '/project/policies/password-dictionary', $serverHeaders, [
            'enabled' => false,
        ]);
    }
}
