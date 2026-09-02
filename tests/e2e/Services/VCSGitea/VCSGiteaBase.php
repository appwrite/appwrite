<?php

namespace Tests\E2E\Services\VCSGitea;

use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;
use Utopia\System\System;

trait VCSGiteaBase
{
    // Admin user created by dev/gitea/setup.sh (run by the CI Gitea job)
    protected const GITEA_USERNAME = 'appwrite';
    protected const GITEA_PASSWORD = 'password';

    protected array $giteaCookies = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (empty(System::getEnv('_APP_VCS_GITEA_CLIENT_ID')) || empty(System::getEnv('_APP_VCS_GITEA_CLIENT_SECRET'))) {
            $this->markTestSkipped('Gitea VCS is not configured.');
        }
    }

    /**
     * Create a console user with their own team and project, so tests can act
     * as two unrelated tenants side by side.
     *
     * @return array{userId: string, email: string, session: string, teamId: string, projectId: string, headers: array<string, string>}
     */
    protected function createTenantHelper(): array
    {
        $email = \uniqid('tenant-', true) . \getmypid() . \bin2hex(\random_bytes(4)) . '@localhost.test';
        $password = 'password';

        $user = $this->client->call(Client::METHOD_POST, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
            'name' => 'VCS Tenant',
        ]);
        $this->assertEquals(201, $user['headers']['status-code']);

        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'email' => $email,
            'password' => $password,
        ]);
        $this->assertEquals(201, $session['headers']['status-code']);
        $this->assertNotEmpty($session['cookies']['a_session_console'] ?? '');
        $sessionCookie = $session['cookies']['a_session_console'];

        // Sessions propagate slowly under parallel load, so retry 401s like ProjectCustom does
        $team = null;
        for ($i = 0; $i < 5; $i++) {
            $team = $this->client->call(Client::METHOD_POST, '/teams', [
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'cookie' => 'a_session_console=' . $sessionCookie,
                'x-appwrite-project' => 'console',
            ], [
                'teamId' => ID::unique(),
                'name' => 'VCS Tenant Team',
            ]);

            if ($team['headers']['status-code'] !== 401) {
                break;
            }

            \usleep(500000);
        }
        $this->assertEquals(201, $team['headers']['status-code']);

        $project = null;
        for ($i = 0; $i < 5; $i++) {
            $project = $this->client->call(Client::METHOD_POST, '/projects', [
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'cookie' => 'a_session_console=' . $sessionCookie,
                'x-appwrite-project' => 'console',
            ], [
                'projectId' => ID::unique(),
                'region' => System::getEnv('_APP_REGION', 'default'),
                'name' => 'VCS Tenant Project',
                'teamId' => $team['body']['$id'],
            ]);

            if ($project['headers']['status-code'] !== 401) {
                break;
            }

            \usleep(500000);
        }
        $this->assertEquals(201, $project['headers']['status-code']);

        return [
            'userId' => $user['body']['$id'],
            'email' => $email,
            'session' => $sessionCookie,
            'teamId' => $team['body']['$id'],
            'projectId' => $project['body']['$id'],
            'headers' => [
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'cookie' => 'a_session_console=' . $sessionCookie,
                'x-appwrite-mode' => 'admin',
            ],
        ];
    }

    protected function createGiteaUserHelper(string $username, string $password): void
    {
        $response = $this->giteaApiHelper(Client::METHOD_POST, '/api/v1/admin/users', [
            'username' => $username,
            'email' => $username . '@localhost.test',
            'password' => $password,
            // Defaults to true, which turns the OAuth2 authorize into a
            // redirect to the change-password page instead of the callback.
            'must_change_password' => false,
        ]);

        // 422 means the user survived a previous run; the Gitea volume persists.
        $this->assertContains($response['headers']['status-code'], [201, 422], \json_encode($response['body']));
    }

    /**
     * Walk the OAuth2 dance against the local Gitea up to the grant and return
     * what the callback expects, asserting every hop on the way.
     *
     * @return array{code: string, state: string, consoleUrl: string}
     */
    protected function authorizeGiteaHelper(?string $projectId = null, ?array $headers = null, string $username = self::GITEA_USERNAME, string $password = self::GITEA_PASSWORD): array
    {
        $projectId ??= $this->getProject()['$id'];
        $headers ??= $this->getHeaders();

        // Start each dance from a fresh jar; Gitea reuses session cookies, so a
        // stale jar would silently keep the previous user logged in.
        $this->giteaCookies = [];

        $consoleUrl = 'http://localhost/console/project-default-' . $projectId . '/settings/git-installations';

        $authorize = $this->client->call(Client::METHOD_GET, '/vcs/gitea/authorize', \array_merge([
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'success' => $consoleUrl,
            'failure' => $consoleUrl,
        ], true, false);

        $this->assertEquals(301, $authorize['headers']['status-code']);

        $loginUrl = $authorize['headers']['location'] ?? '';
        $this->assertStringContainsString('/login/oauth/authorize', (string) $loginUrl);

        $query = [];
        \parse_str(\parse_url($loginUrl, PHP_URL_QUERY) ?: '', $query);
        $this->assertNotEmpty($query['client_id'] ?? '');
        $this->assertNotEmpty($query['redirect_uri'] ?? '');
        $this->assertNotEmpty($query['state'] ?? '');
        $this->assertSame('code', $query['response_type'] ?? '');

        // Location targets the browser-facing endpoint, unreachable in-container; reuse only its path and query
        $gitea = new Client();
        $gitea->setEndpoint(System::getEnv('_APP_VCS_GITEA_ENDPOINT', 'http://gitea:3000'));

        $this->giteaCallHelper($gitea, Client::METHOD_GET, '/user/login');
        $this->assertNotEmpty($this->giteaCookies['_csrf'] ?? '', 'Gitea did not issue a CSRF cookie.');

        $login = $this->giteaCallHelper($gitea, Client::METHOD_POST, '/user/login', [
            '_csrf' => $this->giteaCookies['_csrf'],
            'user_name' => $username,
            'password' => $password,
        ]);
        $this->assertContains($login['headers']['status-code'], [302, 303], 'Gitea login failed.');
        $this->assertNotEmpty($this->giteaCookies['i_like_gitea'] ?? '', 'Gitea did not issue a session cookie.');

        // Gitea stores client_id, state and redirect_uri in the session here; the grant must match them
        $authorizePath = \parse_url($loginUrl, PHP_URL_PATH) . '?' . \parse_url($loginUrl, PHP_URL_QUERY);
        $consent = $this->giteaCallHelper($gitea, Client::METHOD_GET, $authorizePath);

        if (\in_array($consent['headers']['status-code'], [302, 303])) {
            // Already granted: Gitea redirects straight back with a code
            $redirect = $consent['headers']['location'] ?? '';
        } else {
            $this->assertEquals(200, $consent['headers']['status-code']);

            $grant = $this->giteaCallHelper($gitea, Client::METHOD_POST, '/login/oauth/grant', [
                '_csrf' => $this->giteaCookies['_csrf'],
                'client_id' => $query['client_id'],
                'state' => $query['state'],
                'scope' => $query['scope'] ?? '',
                'nonce' => '',
                'redirect_uri' => $query['redirect_uri'],
            ]);
            $this->assertEquals(303, $grant['headers']['status-code']);

            $redirect = $grant['headers']['location'] ?? '';
        }

        $this->assertStringContainsString('/v1/vcs/gitea/callback', (string) $redirect);

        $callbackQuery = [];
        \parse_str(\parse_url($redirect, PHP_URL_QUERY) ?: '', $callbackQuery);
        $this->assertNotEmpty($callbackQuery['code'] ?? '');
        $this->assertNotEmpty($callbackQuery['state'] ?? '');

        return [
            'code' => $callbackQuery['code'],
            'state' => $callbackQuery['state'],
            'consoleUrl' => $consoleUrl,
        ];
    }

    /**
     * Complete the OAuth2 dance and return the resulting installation.
     */
    protected function createInstallationHelper(?string $projectId = null, ?array $headers = null, string $username = self::GITEA_USERNAME, string $password = self::GITEA_PASSWORD): array
    {
        $projectId ??= $this->getProject()['$id'];
        $headers ??= $this->getHeaders();

        ['code' => $code, 'state' => $state, 'consoleUrl' => $consoleUrl] = $this->authorizeGiteaHelper($projectId, $headers, $username, $password);

        $callback = $this->client->call(Client::METHOD_GET, '/vcs/gitea/callback', \array_merge([
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'code' => $code,
            'state' => $state,
        ], true, false);

        $this->assertEquals(301, $callback['headers']['status-code']);
        $this->assertEquals($consoleUrl, $callback['headers']['location'] ?? '');

        $installations = $this->client->call(Client::METHOD_GET, '/vcs/installations', \array_merge([
            'x-appwrite-project' => $projectId,
        ], $headers));

        $this->assertEquals(200, $installations['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $installations['body']['total']);

        foreach ($installations['body']['installations'] as $installation) {
            if ($installation['provider'] === 'gitea') {
                return $installation;
            }
        }

        $this->fail('Gitea installation not found in listInstallations.');
    }

    // Client does not persist cookies between calls, so carry them manually and never follow redirects
    protected function giteaCallHelper(Client $gitea, string $method, string $path, array $params = []): array
    {
        $headers = [];

        if (!empty($this->giteaCookies)) {
            $headers['cookie'] = \implode('; ', \array_map(
                fn (string $name) => $name . '=' . $this->giteaCookies[$name],
                \array_keys($this->giteaCookies)
            ));
        }

        if ($method !== Client::METHOD_GET) {
            $headers['content-type'] = 'application/x-www-form-urlencoded';
        }

        $response = $gitea->call($method, $path, $headers, $params, false, false);

        $this->giteaCookies = \array_merge($this->giteaCookies, $response['cookies']);

        return $response;
    }

    protected function giteaApiHelper(string $method, string $path, array $params = []): array
    {
        $gitea = new Client();
        $gitea->setEndpoint(System::getEnv('_APP_VCS_GITEA_ENDPOINT', 'http://gitea:3000'));

        return $gitea->call($method, $path, [
            'content-type' => 'application/json',
            'authorization' => 'Basic ' . \base64_encode(self::GITEA_USERNAME . ':' . self::GITEA_PASSWORD),
        ], $params);
    }
}
