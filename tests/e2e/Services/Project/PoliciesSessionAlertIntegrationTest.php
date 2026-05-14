<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;

class PoliciesSessionAlertIntegrationTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    public function testSessionAlertIntegration(): void
    {
        $projectId = $this->getProject()['$id'];
        $apiKey = $this->getProject()['apiKey'];
        $password = 'password1234';

        $serverHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
            'x-appwrite-response-format' => '1.9.4',
        ];

        $publicHeaders = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ];

        $setSessionAlert = function (bool $enabled) use ($serverHeaders): void {
            $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/session-alert', $serverHeaders, [
                'enabled' => $enabled,
            ]);
            $this->assertSame(200, $response['headers']['status-code']);
            $this->assertSame($enabled, $response['body']['authSessionAlerts']);
        };

        $createUser = function (string $email) use ($serverHeaders, $password): void {
            $response = $this->client->call(Client::METHOD_POST, '/users', $serverHeaders, [
                'userId' => ID::unique(),
                'email' => $email,
                'password' => $password,
                'name' => 'Alert User',
            ]);
            $this->assertSame(201, $response['headers']['status-code']);
        };

        $createSession = function (string $email) use ($publicHeaders, $password): void {
            $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $publicHeaders, [
                'email' => $email,
                'password' => $password,
            ]);
            $this->assertSame(201, $response['headers']['status-code']);
        };

        $countEmailsTo = function (string $address): int {
            $emails = \json_decode(\file_get_contents('http://maildev:1080/email'), true) ?? [];
            $count = 0;
            foreach ($emails as $email) {
                foreach ($email['to'] ?? [] as $recipient) {
                    if (($recipient['address'] ?? '') === $address) {
                        $count++;
                    }
                }
            }
            return $count;
        };

        $assertEmailCountStays = function (string $address, int $expected, int $seconds) use ($countEmailsTo): void {
            $deadline = \microtime(true) + $seconds;
            while (\microtime(true) < $deadline) {
                $this->assertSame($expected, $countEmailsTo($address), 'Unexpected email count for ' . $address);
                \usleep(500_000);
            }
        };

        // Step 1: Disable session alerts
        $setSessionAlert(false);

        // Step 2: Create user1 and two sessions
        $user1Email = 'alert1_' . uniqid() . '@localhost.test';
        $createUser($user1Email);
        $createSession($user1Email);
        $createSession($user1Email);

        // Step 3: No alert should arrive in the next 10 seconds
        $assertEmailCountStays($user1Email, 0, 10);

        // Step 4: Enable session alerts
        $setSessionAlert(true);

        // Step 5: Create user2 and one session
        $user2Email = 'alert2_' . uniqid() . '@localhost.test';
        $createUser($user2Email);
        $createSession($user2Email);

        // Step 6: First session never alerts, so nothing arrives in 10 seconds
        $assertEmailCountStays($user2Email, 0, 10);

        // Step 7: Create the second session for user2
        $createSession($user2Email);

        // Step 8: Session alert email should eventually arrive
        $this->assertEventually(function () use ($countEmailsTo, $user2Email) {
            $this->assertSame(1, $countEmailsTo($user2Email));
        }, 15_000, 500);

        // Step 9: Disable session alerts
        $setSessionAlert(false);

        // Step 10: Create the third session for user2
        $createSession($user2Email);

        // Step 11: No additional alert email should arrive in 10 seconds
        $assertEmailCountStays($user2Email, 1, 10);
    }
}
