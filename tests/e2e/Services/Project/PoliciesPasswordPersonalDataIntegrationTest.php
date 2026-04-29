<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

class PoliciesPasswordPersonalDataIntegrationTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    public function testPasswordPersonalDataIntegration(): void
    {
        $projectId = $this->getProject()['$id'];
        $apiKey = $this->getProject()['apiKey'];

        $serverHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
        ];

        $setPersonalData = function (bool $enabled) use ($serverHeaders): void {
            $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/password-personal-data', $serverHeaders, [
                'enabled' => $enabled,
            ]);
            $this->assertSame(200, $response['headers']['status-code']);
            $this->assertSame($enabled, $response['body']['authPersonalDataCheck']);
        };

        $buildCases = function (): array {
            $suffix = \uniqid();
            $userId = 'personaluser' . $suffix;
            $emailLocal = 'personalmail' . $suffix;
            $email = $emailLocal . '@localhost.test';
            $name = 'Personalname' . $suffix;
            $phone = '+12025550' . \str_pad((string) \rand(100, 999), 3, '0', STR_PAD_LEFT);

            return [
                'userId' => [
                    'userId' => $userId,
                    'email' => 'safe_' . $suffix . '@localhost.test',
                    'phone' => '+12025559' . \str_pad((string) \rand(100, 999), 3, '0', STR_PAD_LEFT),
                    'name' => 'Safe Name',
                    'password' => $userId . 'extra',
                ],
                'email' => [
                    'userId' => 'safeid' . $suffix,
                    'email' => $email,
                    'phone' => '+12025558' . \str_pad((string) \rand(100, 999), 3, '0', STR_PAD_LEFT),
                    'name' => 'Safe Name',
                    'password' => 'prefix_' . $emailLocal . '_suffix',
                ],
                'name' => [
                    'userId' => 'safeid2' . $suffix,
                    'email' => 'safename_' . $suffix . '@localhost.test',
                    'phone' => '+12025557' . \str_pad((string) \rand(100, 999), 3, '0', STR_PAD_LEFT),
                    'name' => $name,
                    'password' => 'prefix' . $name . 'xyz',
                ],
                'phone' => [
                    'userId' => 'safeid3' . $suffix,
                    'email' => 'safephone_' . $suffix . '@localhost.test',
                    'phone' => $phone,
                    'name' => 'Safe Name',
                    'password' => 'prefix' . \str_replace('+', '', $phone) . 'xyz',
                ],
            ];
        };

        $createUser = function (array $params) use ($serverHeaders): array {
            return $this->client->call(Client::METHOD_POST, '/users', $serverHeaders, [
                'userId' => $params['userId'],
                'email' => $params['email'],
                'phone' => $params['phone'],
                'password' => $params['password'],
                'name' => $params['name'],
            ]);
        };

        // Step 1: Enable password personal data policy
        $setPersonalData(true);

        // Step 2: Each of the four personal-data fields in the password must block user creation
        foreach ($buildCases() as $field => $params) {
            $response = $createUser($params);
            $this->assertSame(400, $response['headers']['status-code'], 'Password containing ' . $field . ' should be rejected');
            $this->assertSame('password_personal_data', $response['body']['type']);
        }

        // Step 3: Disable password personal data policy
        $setPersonalData(false);

        // Step 4: The same categories of passwords should now be accepted (fresh data to avoid uniqueness conflicts)
        foreach ($buildCases() as $field => $params) {
            $response = $createUser($params);
            $this->assertSame(201, $response['headers']['status-code'], 'Password containing ' . $field . ' should be accepted with policy disabled');
            $this->assertSame($params['userId'], $response['body']['$id']);
        }
    }
}
