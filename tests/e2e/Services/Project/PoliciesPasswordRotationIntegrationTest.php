<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Validator\Authorization;

final class PoliciesPasswordRotationIntegrationTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    public function testDefaults(): void
    {
        $policy = $this->client->call(Client::METHOD_GET, '/project/policies/password-rotation', $this->serverHeaders());

        $this->assertSame(200, $policy['headers']['status-code']);
        $this->assertFalse($policy['body']['enabled']);
        $this->assertSame(31536000, $policy['body']['maxAge']);

        $email = Id::unique() . '@localhost.test';
        $password = 'Rotation-' . Id::unique();
        $userId = $this->createUser($email, $password);
        $this->setPasswordUpdate($userId, DateTime::format(new \DateTime('-366 days')));

        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $this->clientHeaders(), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertSame(201, $session['headers']['status-code']);
    }

    public function testCreateSession(): void
    {
        $email = Id::unique() . '@localhost.test';
        $password = 'Rotation-' . Id::unique();
        $userId = $this->createUser($email, $password);

        try {
            $this->updatePolicy(true, 86400);

            foreach (['now', '-23 hours'] as $age) {
                $this->setPasswordUpdate($userId, DateTime::format(new \DateTime($age)));
                $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $this->clientHeaders(), [
                    'email' => $email,
                    'password' => $password,
                ]);

                $this->assertSame(201, $session['headers']['status-code']);
            }

            $this->setPasswordUpdate($userId, DateTime::format(new \DateTime('-25 hours')));
            $sessions = $this->client->call(Client::METHOD_GET, '/users/' . $userId . '/sessions', $this->serverHeaders());
            $this->assertSame(200, $sessions['headers']['status-code']);
            $total = $sessions['body']['total'];

            $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $this->clientHeaders(), [
                'email' => $email,
                'password' => $password,
            ]);

            $this->assertSame(412, $session['headers']['status-code']);
            $this->assertSame('user_password_reset_required', $session['body']['type']);
            $this->assertArrayNotHasKey('a_session_' . $this->getProject()['$id'], $session['cookies']);

            $sessions = $this->client->call(Client::METHOD_GET, '/users/' . $userId . '/sessions', $this->serverHeaders());
            $this->assertSame(200, $sessions['headers']['status-code']);
            $this->assertSame($total, $sessions['body']['total']);

            $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $this->clientHeaders(), [
                'email' => $email,
                'password' => 'Wrong-' . $password,
            ]);

            $this->assertSame(401, $session['headers']['status-code']);
            $this->assertSame('user_invalid_credentials', $session['body']['type']);

            $this->updatePolicy(true, 172800);
            $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $this->clientHeaders(), [
                'email' => $email,
                'password' => $password,
            ]);
            $this->assertSame(201, $session['headers']['status-code']);

            $this->updatePolicy(false, 86400);
            $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $this->clientHeaders(), [
                'email' => $email,
                'password' => $password,
            ]);
            $this->assertSame(201, $session['headers']['status-code']);
        } finally {
            $this->updatePolicy(false, 31536000);
        }
    }

    public function testCreateSessionAfterRecovery(): void
    {
        $email = Id::unique() . '@localhost.test';
        $password = 'Rotation-' . Id::unique();
        $userId = $this->createUser($email, $password);
        $this->setPasswordUpdate($userId, DateTime::format(new \DateTime('-2 days')));

        try {
            $this->updatePolicy(true, 86400);
            $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $this->clientHeaders(), [
                'email' => $email,
                'password' => $password,
            ]);
            $this->assertSame(412, $session['headers']['status-code']);

            $recovery = $this->client->call(Client::METHOD_POST, '/account/recovery', $this->clientHeaders(), [
                'email' => $email,
                'url' => 'http://localhost/recovery',
            ]);
            $this->assertSame(201, $recovery['headers']['status-code']);

            $mail = $this->getLastEmailByAddress($email, function ($mail) {
                $this->assertStringContainsString('Password Reset', (string) $mail['subject']);
            });
            $secret = $this->extractQueryParamsFromEmailLink($mail['html'])['secret'];
            $password = 'Reset-' . Id::unique();

            $recovery = $this->client->call(Client::METHOD_PUT, '/account/recovery', $this->clientHeaders(), [
                'userId' => $userId,
                'secret' => $secret,
                'password' => $password,
            ]);
            $this->assertSame(200, $recovery['headers']['status-code']);

            $user = $this->client->call(Client::METHOD_GET, '/users/' . $userId, $this->serverHeaders());
            $this->assertSame(200, $user['headers']['status-code']);
            $this->assertGreaterThan(new \DateTime('-1 day'), new \DateTime($user['body']['passwordUpdate']));

            $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $this->clientHeaders(), [
                'email' => $email,
                'password' => $password,
            ]);
            $this->assertSame(201, $session['headers']['status-code']);
        } finally {
            $this->updatePolicy(false, 31536000);
        }
    }

    private function createUser(string $email, string $password): string
    {
        $user = $this->client->call(Client::METHOD_POST, '/users', $this->serverHeaders(), [
            'userId' => Id::unique(),
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertSame(201, $user['headers']['status-code']);

        return $user['body']['$id'];
    }

    private function setPasswordUpdate(string $userId, string $date): void
    {
        $seed = function () use ($userId, $date) {
            global $register;
            $pools = $register->get('pools');
            $cache = $register->get('cache');
            $authorization = new Authorization();
            $factory = $register->get('databaseFactory')($pools, $cache, $authorization);

            $authorization->skip(function () use ($factory, $userId, $date) {
                $project = $factory->platform()->getDocument('projects', $this->getProject()['$id']);
                $database = $factory->project($project);
                $database->updateDocument('users', $userId, new Document([
                    'passwordUpdate' => $date,
                ]));
                $database->purgeCachedDocument('users', $userId);
            });
        };

        if (\Swoole\Coroutine::getCid() >= 0) {
            $seed();
        } else {
            \Swoole\Coroutine\run($seed);
        }
    }

    private function updatePolicy(bool $enabled, int $maxAge): void
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/password-rotation', $this->serverHeaders(), [
            'enabled' => $enabled,
            'maxAge' => $maxAge,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
    }

    private function serverHeaders(): array
    {
        return array_merge($this->clientHeaders(), $this->getHeaders());
    }

    private function clientHeaders(): array
    {
        return [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];
    }
}
