<?php

declare(strict_types=1);

namespace Tests\E2E\Services\VCSGitea;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;
use Utopia\System\System;

final class VCSGiteaConsoleClientTest extends Scope
{
    use VCSGiteaBase;
    use ProjectCustom;
    use SideConsole;

    // Admin user created by gitea-bootstrap (docker-compose.override.yml, `gitea` profile)
    private const GITEA_USERNAME = 'appwrite';
    private const GITEA_PASSWORD = 'password';

    private array $giteaCookies = [];

    public function testCreateInstallation(): void
    {
        $projectId = $this->getProject()['$id'];
        $consoleUrl = 'http://localhost/console/project-default-' . $projectId . '/settings/git-installations';

        $authorize = $this->client->call(Client::METHOD_GET, '/vcs/gitea/authorize', array_merge([
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
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

        $this->giteaCall($gitea, Client::METHOD_GET, '/user/login');
        $this->assertNotEmpty($this->giteaCookies['_csrf'] ?? '', 'Gitea did not issue a CSRF cookie.');

        $login = $this->giteaCall($gitea, Client::METHOD_POST, '/user/login', [
            '_csrf' => $this->giteaCookies['_csrf'],
            'user_name' => self::GITEA_USERNAME,
            'password' => self::GITEA_PASSWORD,
        ]);
        $this->assertContains($login['headers']['status-code'], [302, 303], 'Gitea login failed.');
        $this->assertNotEmpty($this->giteaCookies['i_like_gitea'] ?? '', 'Gitea did not issue a session cookie.');

        // Gitea stores client_id, state and redirect_uri in the session here; the grant must match them
        $authorizePath = \parse_url($loginUrl, PHP_URL_PATH) . '?' . \parse_url($loginUrl, PHP_URL_QUERY);
        $consent = $this->giteaCall($gitea, Client::METHOD_GET, $authorizePath);

        if (\in_array($consent['headers']['status-code'], [302, 303])) {
            // Already granted: Gitea redirects straight back with a code
            $redirect = $consent['headers']['location'] ?? '';
        } else {
            $this->assertEquals(200, $consent['headers']['status-code']);

            $grant = $this->giteaCall($gitea, Client::METHOD_POST, '/login/oauth/grant', [
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

        $callback = $this->client->call(Client::METHOD_GET, '/vcs/gitea/callback', array_merge([
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'code' => $callbackQuery['code'],
            'state' => $callbackQuery['state'],
        ], true, false);

        $this->assertEquals(301, $callback['headers']['status-code']);
        $this->assertEquals($consoleUrl, $callback['headers']['location'] ?? '');

        $installations = $this->client->call(Client::METHOD_GET, '/vcs/installations', array_merge([
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()));

        $this->assertEquals(200, $installations['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $installations['body']['total']);

        $installation = null;
        foreach ($installations['body']['installations'] as $candidate) {
            if ($candidate['provider'] === 'gitea') {
                $installation = $candidate;
                break;
            }
        }

        $this->assertNotNull($installation, 'Gitea installation not found in listInstallations.');
        $this->assertNotEmpty($installation['$id']);
        $this->assertEquals('gitea', $installation['provider']);
        $this->assertEquals(self::GITEA_USERNAME, $installation['organization']);
        $this->assertNotEmpty($installation['providerInstallationId']);
    }

    // Client does not persist cookies between calls, so carry them manually and never follow redirects
    private function giteaCall(Client $gitea, string $method, string $path, array $params = []): array
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
}
