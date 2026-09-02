<?php

declare(strict_types=1);

namespace Tests\E2E\Services\VCSGitHub;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;
use Utopia\System\System;

/**
 * Signature verification on the GitHub App installation state. Deliberately
 * does not use VCSGitHubBase: nothing here reaches GitHub, so it must also run
 * on installations that have no GitHub App configured.
 */
final class VCSGitHubStateConsoleClientTest extends Scope
{
    use ProjectCustom;
    use SideConsole;

    private function getRedirect(): string
    {
        return 'http://localhost/console/project-default-' . $this->getProject()['$id'] . '/settings/git-installations';
    }

    /**
     * State signed the way the authorize endpoint signs it.
     *
     * @return array<string, string>
     */
    private function signState(string $projectId, string $success, string $failure): array
    {
        return [
            'projectId' => $projectId,
            'success' => $success,
            'failure' => $failure,
            'signature' => hash_hmac('sha256', json_encode([$projectId, $success, $failure]), System::getEnv('_APP_OPENSSL_KEY_V1', '')),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function getCallbackHeaders(): array
    {
        return array_merge(['x-appwrite-project' => $this->getProject()['$id']], $this->getHeaders());
    }

    /**
     * Without GitHub App credentials the flow cannot go past the callback, so
     * an untampered state reaching the redirect stands in for the happy path.
     */
    public function testGetCallbackSignedState(): void
    {
        $redirect = $this->getRedirect();

        $response = $this->client->call(Client::METHOD_GET, '/vcs/github/callback', $this->getCallbackHeaders(), [
            'setup_action' => 'request',
            'state' => json_encode($this->signState($this->getProject()['$id'], $redirect, $redirect)),
        ], followRedirects: false);

        $this->assertEquals(301, $response['headers']['status-code']);
        $this->assertStringStartsWith($redirect, (string) $response['headers']['location']);
    }

    public function testGetCallbackUnsignedState(): void
    {
        $redirect = $this->getRedirect();
        $state = $this->signState($this->getProject()['$id'], $redirect, $redirect);
        unset($state['signature']);

        $response = $this->client->call(Client::METHOD_GET, '/vcs/github/callback', $this->getCallbackHeaders(), [
            'setup_action' => 'install',
            'installation_id' => '1234567',
            'state' => json_encode($state),
        ], followRedirects: false);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testGetCallbackTamperedState(): void
    {
        $redirect = $this->getRedirect();

        $tampered = [
            ['projectId' => 'victim-project'],
            ['success' => 'https://evil.example/steal'],
            ['failure' => 'https://evil.example/steal'],
        ];

        foreach ($tampered as $mutation) {
            $state = array_merge($this->signState($this->getProject()['$id'], $redirect, $redirect), $mutation);

            $response = $this->client->call(Client::METHOD_GET, '/vcs/github/callback', $this->getCallbackHeaders(), [
                'setup_action' => 'install',
                'installation_id' => '1234567',
                'state' => json_encode($state),
            ], followRedirects: false);

            $this->assertEquals(400, $response['headers']['status-code'], json_encode($mutation));
        }
    }

    public function testGetCallbackReplayedSignature(): void
    {
        $redirect = $this->getRedirect();

        $state = $this->signState($this->getProject()['$id'], $redirect, $redirect);
        $state['signature'] = $this->signState('victim-project', $redirect, $redirect)['signature'];

        $response = $this->client->call(Client::METHOD_GET, '/vcs/github/callback', $this->getCallbackHeaders(), [
            'setup_action' => 'install',
            'installation_id' => '1234567',
            'state' => json_encode($state),
        ], followRedirects: false);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testGetCallbackNonStringSignature(): void
    {
        $redirect = $this->getRedirect();
        $state = $this->signState($this->getProject()['$id'], $redirect, $redirect);

        // hash_equals() throws on non-string input, so this must reject as an
        // invalid state rather than surface a 500.
        $response = $this->client->call(Client::METHOD_GET, '/vcs/github/callback', $this->getCallbackHeaders(), [
            'setup_action' => 'install',
            'installation_id' => '1234567',
            'state' => json_encode(array_merge($state, ['signature' => 1234])),
        ], followRedirects: false);

        $this->assertEquals(400, $response['headers']['status-code']);
    }
}
