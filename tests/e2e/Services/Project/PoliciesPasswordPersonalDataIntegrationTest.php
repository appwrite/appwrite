<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

final class PoliciesPasswordPersonalDataIntegrationTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    private function getServerHeaders(): array
    {
        return [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
            'x-appwrite-response-format' => '1.9.4',
        ];
    }

    /**
     * @param  array<string, bool>  $params
     */
    private function setPersonalDataPolicy(array $params): void
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/password-personal-data', $this->getServerHeaders(), $params);
        $this->assertSame(200, $response['headers']['status-code']);
    }

    private function disableAllPersonalDataChecks(): void
    {
        $this->setPersonalDataPolicy([
            'userId' => false,
            'userEmail' => false,
            'userName' => false,
            'userPhone' => false,
        ]);
    }

    private function buildUser(string $suffix): array
    {
        return [
            'userId' => 'personaluser' . $suffix,
            'emailLocal' => 'personalmail' . $suffix,
            'email' => 'personalmail' . $suffix . '@localhost.test',
            'name' => 'Personalname' . $suffix,
            'phone' => '+1202555' . \str_pad((string) \abs(\crc32($suffix) % 10000), 4, '0', STR_PAD_LEFT),
        ];
    }

    private function createUser(array $user, string $password): array
    {
        return $this->client->call(Client::METHOD_POST, '/users', $this->getServerHeaders(), [
            'userId' => $user['userId'],
            'email' => $user['email'],
            'phone' => $user['phone'],
            'password' => $password,
            'name' => $user['name'],
        ]);
    }

    public function testPasswordPersonalDataAllTogglesEnabledBlocksAllFields(): void
    {
        $this->setPersonalDataPolicy([
            'userId' => true,
            'userEmail' => true,
            'userName' => true,
            'userPhone' => true,
        ]);

        $user = $this->buildUser(\uniqid());

        $cases = [
            'userId' => $user['userId'] . 'extra',
            'email' => 'prefix_' . $user['emailLocal'] . '_suffix',
            'name' => 'prefix' . $user['name'] . 'xyz',
            'phone' => 'prefix' . \str_replace('+', '', $user['phone']) . 'xyz',
        ];

        foreach ($cases as $field => $password) {
            $response = $this->createUser($user, $password);
            $this->assertSame(400, $response['headers']['status-code'], "Password containing {$field} should be rejected");
            $this->assertSame('password_personal_data', $response['body']['type']);
        }

        $this->disableAllPersonalDataChecks();
    }

    public function testPasswordPersonalDataDisabledAllowsAllFields(): void
    {
        $this->disableAllPersonalDataChecks();

        $user = $this->buildUser(\uniqid());

        $cases = [
            'userId' => $user['userId'] . 'extra',
            'email' => 'prefix_' . $user['emailLocal'] . '_suffix',
            'name' => 'prefix' . $user['name'] . 'xyz',
            'phone' => 'prefix' . \str_replace('+', '', $user['phone']) . 'xyz',
        ];

        foreach ($cases as $field => $password) {
            $response = $this->createUser($user, $password);
            $this->assertSame(201, $response['headers']['status-code'], "Password containing {$field} should be accepted when policy disabled");
        }
    }

    public function testPasswordPersonalDataIndividualToggles(): void
    {
        $fieldMap = [
            'userId' => fn (array $u) => $u['userId'] . 'extra',
            'userEmail' => fn (array $u) => 'prefix_' . $u['emailLocal'] . '_suffix',
            'userName' => fn (array $u) => 'prefix' . $u['name'] . 'xyz',
            'userPhone' => fn (array $u) => 'prefix' . \str_replace('+', '', $u['phone']) . 'xyz',
        ];

        foreach ($fieldMap as $toggle => $passwordFn) {
            // Enable only this one toggle; disable all others
            $params = \array_fill_keys(\array_keys($fieldMap), false);
            $params[$toggle] = true;
            $this->setPersonalDataPolicy($params);

            $user = $this->buildUser(\uniqid());
            $password = $passwordFn($user);

            // The enabled toggle must block
            $blocked = $this->createUser($user, $password);
            $this->assertSame(400, $blocked['headers']['status-code'], "Toggle {$toggle}: password containing {$toggle} data should be rejected");
            $this->assertSame('password_personal_data', $blocked['body']['type']);

            // A safe password must pass
            $safe = $this->createUser($user, 'SafeP@ssword123!');
            $this->assertSame(201, $safe['headers']['status-code'], "Toggle {$toggle}: safe password should be accepted");

            $this->disableAllPersonalDataChecks();
        }
    }

    public function testPasswordPersonalDataSelectiveTogglesAllowOtherFields(): void
    {
        // Only userId is enforced; email/name/phone in the password must be allowed
        $this->setPersonalDataPolicy([
            'userId' => true,
            'userEmail' => false,
            'userName' => false,
            'userPhone' => false,
        ]);

        $user = $this->buildUser(\uniqid());

        // Password containing userId → blocked
        $blocked = $this->createUser($user, $user['userId'] . 'extra');
        $this->assertSame(400, $blocked['headers']['status-code']);
        $this->assertSame('password_personal_data', $blocked['body']['type']);

        // Password containing email local part → allowed (toggle off)
        $allowed = $this->createUser($user, 'prefix_' . $user['emailLocal'] . '_suffix');
        $this->assertSame(201, $allowed['headers']['status-code'], 'Email in password should be allowed when userEmail toggle is off');

        $this->disableAllPersonalDataChecks();
    }
}
