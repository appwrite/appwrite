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

    // Signed like the authorize endpoint does: harmless while the callback
    // does not verify signatures, green once it does.
    private function buildState(string $projectId, string $success, string $failure): string
    {
        return (string) json_encode([
            'projectId' => $projectId,
            'success' => $success,
            'failure' => $failure,
            'signature' => hash_hmac('sha256', json_encode([$projectId, $success, $failure]), System::getEnv('_APP_OPENSSL_KEY_V1', '')),
        ]);
    }

    private function getState(): string
    {
        $redirect = $this->getRedirect();

        return $this->buildState($this->getProject()['$id'], $redirect, $redirect);
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

    public function testGetCallbackEmptyState(): void
    {
        $response = $this->client->call(Client::METHOD_GET, '/vcs/github/callback', $this->getCallbackHeaders(), [
            'setup_action' => 'install',
            'installation_id' => '1234567',
            'state' => '',
        ], followRedirects: false);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testGetCallbackProjectNotFound(): void
    {
        $redirect = $this->getRedirect();

        $response = $this->client->call(Client::METHOD_GET, '/vcs/github/callback', $this->getCallbackHeaders(), [
            'setup_action' => 'install',
            'installation_id' => '1234567',
            'state' => $this->buildState('missing-project', $redirect, $redirect),
        ], followRedirects: false);

        $this->assertEquals(301, $response['headers']['status-code']);
        $this->assertStringStartsWith($redirect . '?error=', (string) $response['headers']['location']);
    }

    public function testGetCallbackDefaultRedirect(): void
    {
        $response = $this->client->call(Client::METHOD_GET, '/vcs/github/callback', $this->getCallbackHeaders(), [
            'setup_action' => 'request',
            'state' => $this->buildState($this->getProject()['$id'], '', ''),
        ], followRedirects: false);

        $this->assertEquals(301, $response['headers']['status-code']);
        $this->assertStringStartsWith($this->getRedirect() . '?error=', (string) $response['headers']['location']);
    }

    public function testGetCallbackInvalidJsonState(): void
    {
        $response = $this->client->call(Client::METHOD_GET, '/vcs/github/callback', $this->getCallbackHeaders(), [
            'setup_action' => 'install',
            'installation_id' => '1234567',
            'state' => 'not-json',
        ], followRedirects: false);

        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals('project_not_found', $response['body']['type']);
    }

    public function testGetCallbackLongState(): void
    {
        // Above the old 2048 cap: redirect URLs are not length-limited, so a
        // state this size is one the server itself can produce.
        $failure = $this->getRedirect() . '?pad=' . str_repeat('a', 2400);

        $response = $this->client->call(Client::METHOD_GET, '/vcs/github/callback', $this->getCallbackHeaders(), [
            'setup_action' => 'request',
            'state' => $this->buildState($this->getProject()['$id'], $this->getRedirect(), $failure),
        ], followRedirects: false);

        $this->assertEquals(301, $response['headers']['status-code']);
        $this->assertStringStartsWith($failure, (string) $response['headers']['location']);
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
