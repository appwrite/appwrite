<?php

declare(strict_types=1);

namespace Tests\E2E\Services\VCSGitHub;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;
use Utopia\System\System;

/**
 * Failure paths of the GitHub App installation callback. Deliberately does not
 * use VCSGitHubBase: none of these reach GitHub, so they must also run on
 * installations that have no GitHub App configured.
 */
final class VCSGitHubCallbackConsoleClientTest extends Scope
{
    use ProjectCustom;
    use SideConsole;

    private function getRedirect(): string
    {
        return 'http://localhost/console/project-default-' . $this->getProject()['$id'] . '/settings/git-installations';
    }


    private function getState(): string
    {
        $projectId = $this->getProject()['$id'];
        $redirect = $this->getRedirect();

        // Signed like the authorize endpoint does: harmless while the callback
        // does not verify signatures, green once it does.
        return (string) json_encode([
            'projectId' => $projectId,
            'success' => $redirect,
            'failure' => $redirect,
            'signature' => hash_hmac('sha256', json_encode([$projectId, $redirect, $redirect]), System::getEnv('_APP_OPENSSL_KEY_V1', '')),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function getCallbackHeaders(): array
    {
        return array_merge(['x-appwrite-project' => $this->getProject()['$id']], $this->getHeaders());
    }

    /**
     * GitHub sends the user back without an installation id when an organisation
     * member can only request the installation. The console has to receive that
     * as a query string, not glued onto the path.
     */
    public function testGetCallbackInstallationRequest(): void
    {
        $response = $this->client->call(Client::METHOD_GET, '/vcs/github/callback', $this->getCallbackHeaders(), [
            'setup_action' => 'request',
            'state' => $this->getState(),
        ], followRedirects: false);

        $this->assertEquals(301, $response['headers']['status-code']);
        $this->assertStringStartsWith($this->getRedirect() . '?error=', (string) $response['headers']['location']);
    }

    public function testGetCallbackMissingState(): void
    {
        $response = $this->client->call(Client::METHOD_GET, '/vcs/github/callback', $this->getCallbackHeaders(), [
            'setup_action' => 'install',
            'installation_id' => '1234567',
        ], followRedirects: false);

        $this->assertEquals(400, $response['headers']['status-code']);
    }






    public function testGetCallbackUnexpectedSetupAction(): void
    {
        $response = $this->client->call(Client::METHOD_GET, '/vcs/github/callback', $this->getCallbackHeaders(), [
            'setup_action' => 'foo',
            'state' => $this->getState(),
        ], followRedirects: false);

        $this->assertEquals(301, $response['headers']['status-code']);
        $this->assertStringStartsWith($this->getRedirect() . '?error=', (string) $response['headers']['location']);
    }
}
