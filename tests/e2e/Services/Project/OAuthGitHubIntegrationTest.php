<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;
use Utopia\System\System;

class OAuthGitHubIntegrationTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    public function testGitHubOAuthIntegration(): void
    {
        $clientId = \getenv('_TESTS_OAUTH2_GITHUB_CLIENT_ID');
        $clientSecret = \getenv('_TESTS_OAUTH2_GITHUB_CLIENT_SECRET');

        if (empty($clientId) || empty($clientSecret)) {
            $this->markTestSkipped('GitHub OAuth2 credentials not configured (_TESTS_OAUTH2_GITHUB_CLIENT_ID, _TESTS_OAUTH2_GITHUB_CLIENT_SECRET)');
        }

        $consoleHeaders = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
            'x-appwrite-response-format' => '1.9.4',
        ];

        // Step 1: Create new organization (team)
        $team = $this->client->call(Client::METHOD_POST, '/teams', $consoleHeaders, [
            'teamId' => ID::unique(),
            'name' => 'GitHub OAuth Org ' . uniqid(),
        ]);
        $this->assertSame(201, $team['headers']['status-code']);
        $teamId = $team['body']['$id'];

        // Step 2: Create new project
        $project = $this->client->call(Client::METHOD_POST, '/projects', $consoleHeaders, [
            'projectId' => 'githuboauthapp', // Must be this ID, its used in redirect URL set in GitHub app configuration
            'name' => 'GitHub OAuth Project',
            'teamId' => $teamId,
            'region' => System::getEnv('_APP_REGION', 'default'),
        ]);
        $this->assertSame(201, $project['headers']['status-code']);
        $newProjectId = $project['body']['$id'];

        // Step 3: Configure GitHub provider on the new project via PATCH /v1/project/oauth2/github
        $newProjectAdminHeaders = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => $newProjectId,
            'x-appwrite-mode' => 'admin',
        ];

        $configResponse = $this->client->call(Client::METHOD_PATCH, '/project/oauth2/github', $newProjectAdminHeaders, [
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
            'enabled' => true,
        ]);
        $this->assertSame(200, $configResponse['headers']['status-code']);
        $this->assertTrue($configResponse['body']['enabled']);
        $this->assertSame($clientId, $configResponse['body']['clientId']);

        // Step 4: Verify OAuth provider is enabled via GET /v1/projects/:projectId
        $projectDetails = $this->client->call(Client::METHOD_GET, '/projects/' . $newProjectId, $consoleHeaders);
        $this->assertSame(200, $projectDetails['headers']['status-code']);

        $githubProvider = null;
        foreach ($projectDetails['body']['oAuthProviders'] as $provider) {
            if ($provider['key'] === 'github') {
                $githubProvider = $provider;
                break;
            }
        }
        $this->assertNotNull($githubProvider, 'GitHub OAuth provider not found in project details');
        $this->assertTrue($githubProvider['enabled']);
        $this->assertSame($clientId, $githubProvider['appId']);
        $this->assertSame('', $githubProvider['secret']); // Write only

        // Step 5: Without client headers (no API key), go through the OAuth flow
        $clientHeaders = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $newProjectId,
        ];

        $oauthInit = $this->client->call(
            Client::METHOD_GET,
            '/account/sessions/oauth2/github',
            $clientHeaders,
            [
                'success' => 'http://localhost:4000/success',
                'failure' => 'http://localhost:4000/failure',
            ],
            followRedirects: false
        );

        $this->assertSame(301, $oauthInit['headers']['status-code']);
        $this->assertArrayHasKey('location', $oauthInit['headers']);
        $this->assertStringStartsWith('https://github.com/login/oauth/authorize', $oauthInit['headers']['location']);
        $this->assertStringContainsString('client_id=' . \urlencode($clientId), $oauthInit['headers']['location']);
        $this->assertStringContainsString('redirect_uri=', $oauthInit['headers']['location']);

        // Follow the redirect to GitHub's authorization endpoint. With a real user agent, GitHub
        // would prompt for login + app approval, then redirect back to Appwrite's callback with a
        // valid `code`. Appwrite would then exchange the code, create the session and redirect to
        // the success URL with the session cookie set.
        $oauthClient = new Client();
        $oauthClient->setEndpoint('');

        $githubResponse = $oauthClient->call(
            Client::METHOD_GET,
            $oauthInit['headers']['location'],
            [],
            [],
            decode: false,
            followRedirects: false
        );

        // GitHub returns 200 (login HTML) or 302 (redirect to login) — both indicate the flow
        // reached GitHub. Anything else means our redirect is malformed.
        $this->assertContains($githubResponse['headers']['status-code'], [200, 302]);

        // Cleanup: delete the project
        $deleteProject = $this->client->call(Client::METHOD_DELETE, '/projects/' . $newProjectId, $consoleHeaders);
        $this->assertSame(204, $deleteProject['headers']['status-code']);

        // Cleanup: delete the organization (team)
        $deleteTeam = $this->client->call(Client::METHOD_DELETE, '/teams/' . $teamId, $consoleHeaders);
        $this->assertSame(204, $deleteTeam['headers']['status-code']);
    }
}
