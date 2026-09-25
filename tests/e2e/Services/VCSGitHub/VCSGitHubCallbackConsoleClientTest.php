<?php

declare(strict_types=1);

namespace Tests\E2E\Services\VCSGitHub;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;

/**
 * Deliberately does not use VCSGitHubBase: none of these reach GitHub, so they
 * must also run where no GitHub App is configured.
 */
final class VCSGitHubCallbackConsoleClientTest extends Scope
{
    use ProjectCustom;
    use SideConsole;

    /**
     * @return array{state: string, cookie: string}
     */
    private function authorizeHelper(string $projectId): array
    {
        $settingsUrl = $this->settingsUrl($projectId);

        $response = $this->client->call(Client::METHOD_GET, '/vcs/github/authorize', \array_merge([
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'success' => $settingsUrl,
            'failure' => $settingsUrl,
        ], true, false);

        $this->assertEquals(301, $response['headers']['status-code']);

        $query = [];
        \parse_str(\parse_url((string) $response['headers']['location'], PHP_URL_QUERY) ?: '', $query);

        // The state cookie is the only one Authorize sets
        $name = (string) \array_key_first($response['cookies']);

        return [
            'state' => (string) ($query['state'] ?? ''),
            'cookie' => $name . '=' . ($response['cookies'][$name] ?? ''),
        ];
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private function callGitHubCallbackHelper(array $params, string ...$cookies): array
    {
        $headers = \array_merge(['x-appwrite-project' => $this->getProject()['$id']], $this->getHeaders());

        foreach ($cookies as $cookie) {
            $headers['cookie'] .= '; ' . $cookie;
        }

        return $this->client->call(Client::METHOD_GET, '/vcs/github/callback', $headers, $params, true, false);
    }

    private function settingsUrl(string $projectId): string
    {
        return "http://localhost/projects/{$projectId}/settings";
    }

    public function testCreateInstallationWithoutState(): void
    {
        $response = $this->callGitHubCallbackHelper([
            'setup_action' => 'update',
            'installation_id' => '1234567',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testCreateInstallationWithoutCode(): void
    {
        $projectId = $this->getProject()['$id'];

        // A valid state proves the project, not the installation, so an
        // installation_id without a code must not be linked
        $response = $this->callGitHubCallbackHelper([
            'setup_action' => 'install',
            'installation_id' => '1234567',
            'state' => $this->authorizeHelper($projectId)['state'],
        ]);

        $this->assertEquals(301, $response['headers']['status-code']);
        $this->assertStringStartsWith($this->settingsUrl($projectId) . '?error=', (string) $response['headers']['location']);
    }

    public function testCreateInstallationWithStateCookie(): void
    {
        $projectId = $this->getProject()['$id'];

        // GitHub drops state on an existing installation's update; the failure
        // redirect proves the project came back from the cookie
        $response = $this->callGitHubCallbackHelper([
            'setup_action' => 'update',
            'installation_id' => '1234567',
        ], $this->authorizeHelper($projectId)['cookie']);

        $this->assertEquals(301, $response['headers']['status-code']);
        $this->assertStringStartsWith($this->settingsUrl($projectId) . '?error=', (string) $response['headers']['location']);
    }

    public function testCreateInstallationClearsStateCookie(): void
    {
        $cookie = $this->authorizeHelper($this->getProject()['$id'])['cookie'];

        $response = $this->callGitHubCallbackHelper([
            'setup_action' => 'update',
            'installation_id' => '1234567',
        ], $cookie);

        $this->assertStringStartsWith(\strtok($cookie, '=') . '=deleted;', $response['headers']['set-cookie'] ?? '');
    }

    public function testCreateInstallationKeepsOtherProjectStateCookie(): void
    {
        $other = $this->authorizeHelper($this->getProject(true)['$id'])['cookie'];

        // A flow for this project must not consume a connection pending for another one
        $response = $this->callGitHubCallbackHelper([
            'setup_action' => 'install',
            'installation_id' => '1234567',
            'state' => $this->authorizeHelper($this->getProject()['$id'])['state'],
        ], $other);

        $this->assertStringNotContainsString((string) \strtok($other, '='), (string) ($response['headers']['set-cookie'] ?? ''));
    }

    public function testCreateInstallationWithStateCookiesForTwoProjects(): void
    {
        // With several connections pending, only one this installation could be
        // relinked into may be picked; one linked nowhere fits neither
        $response = $this->callGitHubCallbackHelper([
            'setup_action' => 'update',
            'installation_id' => '1234567',
        ], $this->authorizeHelper($this->getProject()['$id'])['cookie'], $this->authorizeHelper($this->getProject(true)['$id'])['cookie']);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testCreateInstallationWithTamperedStateCookie(): void
    {
        [$name, $value] = \explode('=', $this->authorizeHelper($this->getProject()['$id'])['cookie'], 2);

        $state = \json_decode(\urldecode($value), true);
        $state['projectId'] = 'victim-project';

        $response = $this->callGitHubCallbackHelper([
            'setup_action' => 'update',
            'installation_id' => '1234567',
        ], $name . '=' . \urlencode((string) \json_encode($state)));

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testCreateInstallationWithStateCookieOnInstall(): void
    {
        // Only an update drops state; any other callback must bring its own
        $response = $this->callGitHubCallbackHelper([
            'setup_action' => 'install',
            'installation_id' => '1234567',
        ], $this->authorizeHelper($this->getProject()['$id'])['cookie']);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testCreateInstallationWithStateCookieForUnlinkedInstallation(): void
    {
        $projectId = $this->getProject()['$id'];

        // The cookie rides along on any navigation, so another site could pair it
        // with its own code and installation. Refused before GitHub is asked.
        $response = $this->callGitHubCallbackHelper([
            'setup_action' => 'update',
            'installation_id' => '1234567',
            'code' => 'unused',
        ], $this->authorizeHelper($projectId)['cookie']);

        $this->assertEquals(301, $response['headers']['status-code']);
        $this->assertStringStartsWith($this->settingsUrl($projectId) . '?error=', (string) $response['headers']['location']);
    }
}
