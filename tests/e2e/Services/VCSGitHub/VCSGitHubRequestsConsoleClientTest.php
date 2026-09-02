<?php

declare(strict_types=1);

namespace Tests\E2E\Services\VCSGitHub;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;

/**
 * Member-requested installation flow: request document, webhook approval,
 * Console confirmation. Deliberately does not use VCSGitHubBase: nothing here
 * reaches GitHub, so it must also run on installations that have no GitHub App
 * configured. The request document is seeded through the dev-only mock route,
 * standing in for the request callback whose OAuth code cannot be faked.
 */
final class VCSGitHubRequestsConsoleClientTest extends Scope
{
    use ProjectCustom;
    use SideConsole;

    /**
     * @return array<string, string>
     */
    private function getRequestHeaders(?string $projectId = null): array
    {
        return array_merge(['x-appwrite-project' => $projectId ?? $this->getProject()['$id']], $this->getHeaders());
    }

    private function seedRequest(string $requester): string
    {
        $seeded = $this->client->call(Client::METHOD_GET, '/mock/github/request', $this->getRequestHeaders(), [
            'projectId' => $this->getProject()['$id'],
            'requester' => $requester,
        ]);

        $this->assertEquals(200, $seeded['headers']['status-code']);
        $this->assertNotEmpty($seeded['body']['requestId']);

        return $seeded['body']['requestId'];
    }

    private function approveOnProvider(string $requester, int $providerInstallationId, string $organization): void
    {
        // GitHub webhooks are public and intentionally have no x-appwrite-project header.
        $event = $this->client->call(Client::METHOD_POST, '/vcs/github/events', [
            'content-type' => 'application/json',
            'x-github-event' => 'installation',
        ], [
            'action' => 'created',
            'installation' => [
                'id' => $providerInstallationId,
                'account' => ['login' => $organization],
            ],
            'requester' => ['login' => $requester],
        ]);

        $this->assertEquals(200, $event['headers']['status-code']);
    }

    public function testUpdateRequest(): void
    {
        $requester = uniqid('octocat-');
        $requestId = $this->seedRequest($requester);

        $requests = $this->client->call(Client::METHOD_GET, '/vcs/requests', $this->getRequestHeaders());
        $this->assertEquals(200, $requests['headers']['status-code']);
        $this->assertEquals(1, $requests['body']['total']);
        $this->assertEquals('requested', $requests['body']['requests'][0]['status']);

        // Confirming before the provider approved must fail.
        $early = $this->client->call(Client::METHOD_PATCH, '/vcs/requests/' . $requestId, $this->getRequestHeaders());
        $this->assertEquals(400, $early['headers']['status-code']);
        $this->assertEquals('installation_request_not_ready', $early['body']['type']);

        $this->approveOnProvider($requester, 424242, 'request-test-org');

        $requests = $this->client->call(Client::METHOD_GET, '/vcs/requests', $this->getRequestHeaders());
        $this->assertEquals('ready', $requests['body']['requests'][0]['status']);
        $this->assertEquals('request-test-org', $requests['body']['requests'][0]['organization']);

        $confirmed = $this->client->call(Client::METHOD_PATCH, '/vcs/requests/' . $requestId, $this->getRequestHeaders());
        $this->assertEquals(200, $confirmed['headers']['status-code']);
        $this->assertEquals('github', $confirmed['body']['provider']);
        $this->assertEquals('request-test-org', $confirmed['body']['organization']);
        $this->assertEquals('424242', $confirmed['body']['providerInstallationId']);

        $installations = $this->client->call(Client::METHOD_GET, '/vcs/installations', $this->getRequestHeaders());
        $this->assertEquals(200, $installations['headers']['status-code']);
        $organizations = array_column($installations['body']['installations'], 'organization');
        $this->assertContains('request-test-org', $organizations);

        // Confirming consumes the request.
        $requests = $this->client->call(Client::METHOD_GET, '/vcs/requests', $this->getRequestHeaders());
        $this->assertEquals(0, $requests['body']['total']);
    }

    public function testUpdateRequestForeignProject(): void
    {
        $requester = uniqid('octocat-');
        $requestId = $this->seedRequest($requester);
        $this->approveOnProvider($requester, 424243, 'request-test-org2');

        $foreignProject = $this->getProject(true)['$id'];

        $foreign = $this->client->call(Client::METHOD_PATCH, '/vcs/requests/' . $requestId, $this->getRequestHeaders($foreignProject));
        $this->assertEquals(404, $foreign['headers']['status-code']);
        $this->assertEquals('installation_request_not_found', $foreign['body']['type']);
    }

    public function testDeleteRequest(): void
    {
        $requestId = $this->seedRequest(uniqid('octocat-'));

        $deleted = $this->client->call(Client::METHOD_DELETE, '/vcs/requests/' . $requestId, $this->getRequestHeaders());
        $this->assertEquals(204, $deleted['headers']['status-code']);

        $missing = $this->client->call(Client::METHOD_PATCH, '/vcs/requests/' . $requestId, $this->getRequestHeaders());
        $this->assertEquals(404, $missing['headers']['status-code']);
    }
}
