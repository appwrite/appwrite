<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;
use Utopia\Database\Id;

trait PasswordsDeleteBase
{
    public function testDeletePasswords(): void
    {
        // A dedicated project keeps the project-wide reset from refusing the
        // passwords of any test running in parallel against the shared project.
        self::$project = $this->getProject(true);
        $projectId = $this->getProject()['$id'];

        $projectHeaders = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders());

        $publicHeaders = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ];

        $accountHeaders = function (string $sessionSecret) use ($projectId): array {
            return [
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'cookie' => 'a_session_' . $projectId . '=' . $sessionSecret,
            ];
        };

        $signIn = function (string $email, string $password) use ($publicHeaders): array {
            return $this->client->call(Client::METHOD_POST, '/account/sessions/email', $publicHeaders, [
                'email' => $email,
                'password' => $password,
            ]);
        };

        $createUser = function (string $email, string $password) use ($projectHeaders): string {
            $user = $this->client->call(Client::METHOD_POST, '/users', $projectHeaders, [
                'userId' => Id::unique(),
                'email' => $email,
                'password' => $password,
                'name' => 'Passwords Delete User',
            ]);
            $this->assertSame(201, $user['headers']['status-code']);

            return $user['body']['$id'];
        };

        $password = 'Password123!';

        // Setup: users with a password, one of them signed in already
        $email1 = 'passwords-delete-' . uniqid() . '@localhost.test';
        $email2 = 'passwords-delete-' . uniqid() . '@localhost.test';
        $email3 = 'passwords-delete-' . uniqid() . '@localhost.test';
        $idleEmail = 'passwords-delete-' . uniqid() . '@localhost.test';

        $userId1 = $createUser($email1, $password);
        $userId2 = $createUser($email2, $password);
        $userId3 = $createUser($email3, $password);
        $createUser($idleEmail, $password);

        $session = $signIn($email1, $password);
        $this->assertSame(201, $session['headers']['status-code']);
        $sessionSecret = $session['cookies']['a_session_' . $projectId];

        $sessions = $this->client->call(Client::METHOD_GET, '/users/' . $userId2 . '/sessions', $projectHeaders);
        $this->assertSame(200, $sessions['headers']['status-code']);
        $this->assertSame(0, $sessions['body']['total']);

        /**
         * Test for SUCCESS
         */

        $response = $this->client->call(Client::METHOD_DELETE, '/project/passwords', $projectHeaders, []);
        $this->assertSame(204, $response['headers']['status-code']);

        // Every existing password is refused for sign-in, without creating a session
        $this->assertEventually(function () use ($signIn, $email1, $email2, $email3, $idleEmail, $password, $projectId) {
            foreach ([$email1, $email2, $email3, $idleEmail] as $email) {
                $response = $signIn($email, $password);
                $this->assertSame(412, $response['headers']['status-code']);
                $this->assertSame('user_password_reset_required', $response['body']['type']);
                $this->assertArrayNotHasKey('a_session_' . $projectId, $response['cookies']);
            }
        }, 15_000, 500);

        $sessions = $this->client->call(Client::METHOD_GET, '/users/' . $userId2 . '/sessions', $projectHeaders);
        $this->assertSame(200, $sessions['headers']['status-code']);
        $this->assertSame(0, $sessions['body']['total']);

        // Sessions created before the reset stay signed in
        $account = $this->client->call(Client::METHOD_GET, '/account', $accountHeaders($sessionSecret));
        $this->assertSame(200, $account['headers']['status-code']);
        $this->assertSame($userId1, $account['body']['$id']);

        // A signed-in user resets by changing their password
        $newPassword1 = 'Changed-' . Id::unique();
        $update = $this->client->call(Client::METHOD_PATCH, '/account/password', $accountHeaders($sessionSecret), [
            'password' => $newPassword1,
            'oldPassword' => $password,
        ]);
        $this->assertSame(200, $update['headers']['status-code']);
        $this->assertSame(201, $signIn($email1, $newPassword1)['headers']['status-code']);

        // A signed-out user resets through password recovery
        $recovery = $this->client->call(Client::METHOD_POST, '/account/recovery', $publicHeaders, [
            'email' => $email2,
            'url' => 'http://localhost/recovery',
        ]);
        $this->assertSame(201, $recovery['headers']['status-code']);

        $mail = $this->getLastEmailByAddress($email2, function ($mail) {
            $this->assertStringContainsString('Password Reset', (string) $mail['subject']);
        });
        $secret = $this->extractQueryParamsFromEmailLink($mail['html'])['secret'];

        $newPassword2 = 'Recovered-' . Id::unique();
        $recovery = $this->client->call(Client::METHOD_PUT, '/account/recovery', $publicHeaders, [
            'userId' => $userId2,
            'secret' => $secret,
            'password' => $newPassword2,
        ]);
        $this->assertSame(200, $recovery['headers']['status-code']);
        $this->assertSame(201, $signIn($email2, $newPassword2)['headers']['status-code']);

        // A password set for the user through the Users API counts as a reset
        $newPassword3 = 'Assigned-' . Id::unique();
        $update = $this->client->call(Client::METHOD_PATCH, '/users/' . $userId3 . '/password', $projectHeaders, [
            'password' => $newPassword3,
        ]);
        $this->assertSame(200, $update['headers']['status-code']);
        $this->assertSame(201, $signIn($email3, $newPassword3)['headers']['status-code']);

        // Users created after the reset sign in normally
        $email4 = 'passwords-delete-' . uniqid() . '@localhost.test';
        $createUser($email4, $password);
        $this->assertSame(201, $signIn($email4, $password)['headers']['status-code']);

        /**
         * Test for FAILURE
         */

        // A wrong password keeps the generic error, so the reset reveals nothing
        $response = $signIn($email1, 'Wrong-' . $password);
        $this->assertSame(401, $response['headers']['status-code']);
        $this->assertSame('user_invalid_credentials', $response['body']['type']);

        // A user who has not reset yet stays refused after other users reset theirs
        $response = $signIn($idleEmail, $password);
        $this->assertSame(412, $response['headers']['status-code']);
        $this->assertSame('user_password_reset_required', $response['body']['type']);
    }

    public function testDeletePasswordsScopeFailures(): void
    {
        self::$project = $this->getProject(true);
        $projectId = $this->getProject()['$id'];

        $projectHeaders = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders());

        /**
         * Test for FAILURE
         */

        // A key without the users.write scope is rejected
        $readKey = $this->getNewKey(['users.read']);
        $response = $this->client->call(Client::METHOD_DELETE, '/project/passwords', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $readKey,
        ], []);
        $this->assertSame(401, $response['headers']['status-code']);
        $this->assertSame('general_unauthorized_scope', $response['body']['type']);

        // An ordinary user session is rejected
        $email = 'passwords-delete-' . uniqid() . '@localhost.test';
        $password = 'Password123!';

        $user = $this->client->call(Client::METHOD_POST, '/users', $projectHeaders, [
            'userId' => Id::unique(),
            'email' => $email,
            'password' => $password,
            'name' => 'Passwords Delete Member',
        ]);
        $this->assertSame(201, $user['headers']['status-code']);

        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], [
            'email' => $email,
            'password' => $password,
        ]);
        $this->assertSame(201, $session['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_DELETE, '/project/passwords', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $session['cookies']['a_session_' . $projectId],
        ], []);
        $this->assertSame(401, $response['headers']['status-code']);
        $this->assertSame('general_unauthorized_scope', $response['body']['type']);

        // No credentials at all are rejected
        $response = $this->client->call(Client::METHOD_DELETE, '/project/passwords', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], []);
        $this->assertSame(401, $response['headers']['status-code']);

        // None of the rejected calls reset anything
        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], [
            'email' => $email,
            'password' => $password,
        ]);
        $this->assertSame(201, $session['headers']['status-code']);
    }

    public function testDeletePasswordsCrossProjectIsolation(): void
    {
        self::$project = $this->getProject(true);
        $projectId = $this->getProject()['$id'];
        $password = 'Password123!';

        $otherProject = $this->getProject(true);
        $otherProjectId = $otherProject['$id'];

        $projectHeaders = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders());

        $signIn = function (string $email, string $projectId) use ($password): array {
            return $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
            ], [
                'email' => $email,
                'password' => $password,
            ]);
        };

        // Setup: a user with a password in each project
        $email = 'passwords-delete-' . uniqid() . '@localhost.test';
        $otherEmail = 'passwords-delete-' . uniqid() . '@localhost.test';

        $user = $this->client->call(Client::METHOD_POST, '/users', $projectHeaders, [
            'userId' => Id::unique(),
            'email' => $email,
            'password' => $password,
            'name' => 'Passwords Delete Isolated User',
        ]);
        $this->assertSame(201, $user['headers']['status-code']);

        $otherUser = $this->client->call(Client::METHOD_POST, '/users', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $otherProjectId,
            'x-appwrite-key' => $otherProject['apiKey'],
        ], [
            'userId' => Id::unique(),
            'email' => $otherEmail,
            'password' => $password,
            'name' => 'Passwords Delete Other User',
        ]);
        $this->assertSame(201, $otherUser['headers']['status-code']);

        /**
         * Test for SUCCESS
         */

        // Reset the passwords of the first project only
        $response = $this->client->call(Client::METHOD_DELETE, '/project/passwords', $projectHeaders, []);
        $this->assertSame(204, $response['headers']['status-code']);

        // The first project's password is refused, the other project's still signs in
        $this->assertEventually(function () use ($signIn, $email, $projectId) {
            $response = $signIn($email, $projectId);
            $this->assertSame(412, $response['headers']['status-code']);
            $this->assertSame('user_password_reset_required', $response['body']['type']);
        }, 15_000, 500);

        $this->assertSame(201, $signIn($otherEmail, $otherProjectId)['headers']['status-code']);
    }
}
