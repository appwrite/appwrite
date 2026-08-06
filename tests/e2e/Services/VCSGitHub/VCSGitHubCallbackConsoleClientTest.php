<?php

declare(strict_types=1);

namespace Tests\E2E\Services\VCSGitHub;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;

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
        $redirect = $this->getRedirect();

        return (string) \json_encode([
            'projectId' => $this->getProject()['$id'],
            'success' => $redirect,
            'failure' => $redirect,
        ]);
    }

    /**
     * @param array<string, string> $cookies
     * @return array<string, string>
     */
    private function getCallbackHeaders(array $cookies = []): array
    {
        $headers = \array_merge(['x-appwrite-project' => $this->getProject()['$id']], $this->getHeaders());

        foreach ($cookies as $name => $value) {
            $headers['cookie'] .= '; ' . $name . '=' . \urlencode($value);
        }

        return $headers;
    }

    /**
     * GitHub sends the user back without an installation id when an organisation
     * member can only request the installation. The console has to receive that
     * as a query string, not glued onto the path.
     */
    public function testCallbackRequest(): void
    {
        $response = $this->client->call(Client::METHOD_GET, '/vcs/github/callback', $this->getCallbackHeaders(), [
            'setup_action' => 'request',
            'state' => $this->getState(),
        ], followRedirects: false);

        $this->assertEquals(301, $response['headers']['status-code']);
        $this->assertStringStartsWith($this->getRedirect() . '?error=', (string) $response['headers']['location']);
    }

    /**
     * GitHub drops the state parameter whenever it finishes the flow through the
     * app's setup URL, which is what an organisation member's request does. The
     * cookie the authorize endpoint left behind has to take over, so the user
     * lands back in the console instead of on a bare 400 page.
     */
    public function testCallbackMissingState(): void
    {
        $response = $this->client->call(Client::METHOD_GET, '/vcs/github/callback', $this->getCallbackHeaders([
            'a_vcs_state' => $this->getState(),
        ]), [
            'setup_action' => 'request',
        ], followRedirects: false);

        $this->assertEquals(301, $response['headers']['status-code']);
        $this->assertStringStartsWith($this->getRedirect() . '?error=', (string) $response['headers']['location']);
    }

    /**
     * Without state and without the cookie there is no project to redirect to, so
     * the error has to at least say what went wrong.
     */
    public function testCallbackMissingStateAndCookie(): void
    {
        $response = $this->client->call(Client::METHOD_GET, '/vcs/github/callback', $this->getCallbackHeaders(), [
            'setup_action' => 'install',
            'installation_id' => '1234567',
        ], followRedirects: false);

        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertStringContainsString('Missing state parameter', (string) $response['body']);
    }
}
