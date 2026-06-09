<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;

final class PoliciesPasswordStrengthIntegrationTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    public function testPasswordStrengthIntegration(): void
    {
        $projectId = $this->getProject()['$id'];
        $apiKey = $this->getProject()['apiKey'];

        $serverHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
            'x-appwrite-response-format' => '1.9.4',
        ];

        $signupHeaders = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ];

        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/password-strength', $serverHeaders, [
            'min' => 12,
            'uppercase' => true,
            'lowercase' => true,
            'number' => true,
            'symbols' => true,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(12, $response['body']['min']);
        $this->assertTrue($response['body']['uppercase']);
        $this->assertTrue($response['body']['lowercase']);
        $this->assertTrue($response['body']['number']);
        $this->assertTrue($response['body']['symbols']);

        $weak = $this->client->call(Client::METHOD_POST, '/account', $signupHeaders, [
            'userId' => ID::unique(),
            'email' => 'strength_weak_' . uniqid() . '@localhost.test',
            'password' => 'password123!',
            'name' => 'Weak Password User',
        ]);

        $this->assertSame(400, $weak['headers']['status-code']);

        $valid = $this->client->call(Client::METHOD_POST, '/account', $signupHeaders, [
            'userId' => ID::unique(),
            'email' => 'strength_valid_' . uniqid() . '@localhost.test',
            'password' => 'Password123!',
            'name' => 'Valid Password User',
        ]);

        $this->assertSame(201, $valid['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/password-strength', $serverHeaders, [
            'min' => 8,
            'uppercase' => false,
            'lowercase' => false,
            'number' => false,
            'symbols' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
    }
}
