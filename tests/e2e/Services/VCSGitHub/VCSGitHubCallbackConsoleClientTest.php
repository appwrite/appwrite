<?php

declare(strict_types=1);

namespace Tests\E2E\Services\VCSGitHub;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;
use Utopia\System\System;

/**
 * Deliberately does not use VCSGitHubBase: none of these reach GitHub, so they
 * must also run where no GitHub App is configured.
 */
final class VCSGitHubCallbackConsoleClientTest extends Scope
{
    use ProjectCustom;
    use SideConsole;

    private function buildGitHubState(string $projectId, string $success, string $failure): string
    {
        return (string) \json_encode([
            'projectId' => $projectId,
            'success' => $success,
            'failure' => $failure,
            'signature' => \hash_hmac('sha256', \json_encode([$projectId, $success, $failure]), System::getEnv('_APP_OPENSSL_KEY_V1', '')),
        ]);
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private function callGitHubCallbackHelper(array $params, string $cookie = ''): array
    {
        $headers = \array_merge(['x-appwrite-project' => $this->getProject()['$id']], $this->getHeaders());

        if ($cookie !== '') {
            $headers['cookie'] .= '; a_github_state=' . \urlencode($cookie);
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

    public function testCreateInstallationRequestWithoutState(): void
    {
        // An owner approving a member's request on GitHub comes back without state
        $response = $this->callGitHubCallbackHelper(['setup_action' => 'request']);

        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertStringContainsString('sent to the organization owners', (string) $response['body']);
    }

    public function testCreateInstallationWithoutCode(): void
    {
        $projectId = $this->getProject()['$id'];
        $settingsUrl = $this->settingsUrl($projectId);

        // A valid state proves the project, not the installation, so an
        // installation_id without a code must not be linked
        $response = $this->callGitHubCallbackHelper([
            'setup_action' => 'install',
            'installation_id' => '1234567',
            'state' => $this->buildGitHubState($projectId, $settingsUrl, $settingsUrl),
        ]);

        $this->assertEquals(301, $response['headers']['status-code']);
        $this->assertStringStartsWith($settingsUrl . '?error=', (string) $response['headers']['location']);
    }

    public function testCreateInstallationWithStateCookie(): void
    {
        $projectId = $this->getProject()['$id'];
        $settingsUrl = $this->settingsUrl($projectId);

        // GitHub drops state on an existing installation's update; the failure
        // redirect proves the project came back from the cookie
        $response = $this->callGitHubCallbackHelper([
            'setup_action' => 'update',
            'installation_id' => '1234567',
        ], $this->buildGitHubState($projectId, $settingsUrl, $settingsUrl));

        $this->assertEquals(301, $response['headers']['status-code']);
        $this->assertStringStartsWith($settingsUrl . '?error=', (string) $response['headers']['location']);
    }

    public function testCreateInstallationClearsStateCookie(): void
    {
        $projectId = $this->getProject()['$id'];
        $settingsUrl = $this->settingsUrl($projectId);

        $response = $this->callGitHubCallbackHelper([
            'setup_action' => 'update',
            'installation_id' => '1234567',
        ], $this->buildGitHubState($projectId, $settingsUrl, $settingsUrl));

        $this->assertStringStartsWith('a_github_state=deleted;', $response['headers']['set-cookie'] ?? '');
    }

    public function testCreateInstallationKeepsOtherProjectStateCookie(): void
    {
        $projectId = $this->getProject()['$id'];
        $settingsUrl = $this->settingsUrl($projectId);

        // A flow for this project must not consume a connection pending for another one
        $response = $this->callGitHubCallbackHelper([
            'setup_action' => 'install',
            'installation_id' => '1234567',
            'state' => $this->buildGitHubState($projectId, $settingsUrl, $settingsUrl),
        ], $this->buildGitHubState('other-project', $settingsUrl, $settingsUrl));

        $this->assertStringNotContainsString('a_github_state', (string) ($response['headers']['set-cookie'] ?? ''));
    }

    public function testCreateInstallationWithTamperedStateCookie(): void
    {
        $projectId = $this->getProject()['$id'];
        $settingsUrl = $this->settingsUrl($projectId);

        $state = \json_decode($this->buildGitHubState($projectId, $settingsUrl, $settingsUrl), true);
        $state['projectId'] = 'victim-project';

        $response = $this->callGitHubCallbackHelper([
            'setup_action' => 'update',
            'installation_id' => '1234567',
        ], (string) \json_encode($state));

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testCreateInstallationWithStateCookieOnInstall(): void
    {
        $projectId = $this->getProject()['$id'];
        $settingsUrl = $this->settingsUrl($projectId);

        // Only an update drops state; any other callback must bring its own
        $response = $this->callGitHubCallbackHelper([
            'setup_action' => 'install',
            'installation_id' => '1234567',
        ], $this->buildGitHubState($projectId, $settingsUrl, $settingsUrl));

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testCreateInstallationWithStateCookieForUnlinkedInstallation(): void
    {
        $projectId = $this->getProject()['$id'];
        $settingsUrl = $this->settingsUrl($projectId);

        // The cookie rides along on any navigation, so another site could pair it
        // with its own code and installation. Refused before GitHub is asked.
        $response = $this->callGitHubCallbackHelper([
            'setup_action' => 'update',
            'installation_id' => '1234567',
            'code' => 'unused',
        ], $this->buildGitHubState($projectId, $settingsUrl, $settingsUrl));

        $this->assertEquals(301, $response['headers']['status-code']);
        $this->assertStringStartsWith($settingsUrl . '?error=', (string) $response['headers']['location']);
    }
}
