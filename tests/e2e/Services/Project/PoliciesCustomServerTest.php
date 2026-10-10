<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Project;

use Appwrite\Extend\Exception;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;

final class PoliciesCustomServerTest extends Scope
{
    use PoliciesBase;
    use ProjectCustom;
    use SideServer;

    public function testUpdatePasswordRotationPolicyWithoutScope(): void
    {
        $path = '/projects/' . $this->getProject()['$id'] . '/keys';
        $headers = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ];
        $key = $this->client->call(Client::METHOD_POST, $path, $headers, [
            'keyId' => ID::unique(),
            'name' => 'Read policies',
            'scopes' => ['project.policies.read'],
        ]);
        $this->assertSame(201, $key['headers']['status-code']);

        try {
            $serverHeaders = $this->buildHeaders();
            $serverHeaders['x-appwrite-key'] = $key['body']['secret'];
            $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/password-rotation', $serverHeaders, [
                'enabled' => true,
                'duration' => 30,
            ]);

            $this->assertSame(401, $response['headers']['status-code']);
            $this->assertSame(Exception::GENERAL_UNAUTHORIZED_SCOPE, $response['body']['type']);
        } finally {
            $this->client->call(Client::METHOD_DELETE, $path . '/' . $key['body']['$id'], $headers);
        }
    }

    public function testPasswordPersonalDataPolicyRejectsNullPassword(): void
    {
        $this->updatePasswordPersonalDataPolicy(true);

        try {
            // An explicit null password is checked against personal data rather than treated as an empty password
            $response = $this->client->call(Client::METHOD_POST, '/users', $this->buildHeaders(), [
                'userId' => ID::unique(),
                'email' => \uniqid() . '@example.com',
                'password' => null,
            ]);

            $this->assertSame(400, $response['headers']['status-code']);
            $this->assertSame(Exception::USER_PASSWORD_PERSONAL_DATA, $response['body']['type']);
        } finally {
            $this->updatePasswordPersonalDataPolicy(false);
        }
    }
}
