<?php

declare(strict_types=1);

namespace Tests\E2E\Services\VCSGitHub;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;
use Utopia\System\System;

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

    private function postInstallationEvent(array $payload): void
    {
        // GitHub webhooks are public and intentionally have no x-appwrite-project header.
        $headers = [
            'content-type' => 'application/json',
            'x-github-event' => 'installation',
        ];

        $secret = System::getEnv('_APP_VCS_GITHUB_WEBHOOK_SECRET', '');
        if (!empty($secret)) {
            $headers['x-hub-signature-256'] = 'sha256=' . hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), $secret);
        }

        $event = $this->client->call(Client::METHOD_POST, '/vcs/github/events', $headers, $payload);

        $this->assertEquals(200, $event['headers']['status-code']);
    }

    private function approveOnProvider(string $requester, int $providerInstallationId, string $organization): void
    {
        $this->postInstallationEvent([
            'action' => 'created',
            'installation' => [
                'id' => $providerInstallationId,
                'account' => ['login' => $organization],
            ],
            'requester' => ['login' => $requester],
        ]);
    }

    private function findRequest(string $requestId): ?array
    {
        $requests = $this->client->call(Client::METHOD_GET, '/vcs/requests', $this->getRequestHeaders());
        $this->assertEquals(200, $requests['headers']['status-code']);

        foreach ($requests['body']['requests'] as $request) {
            if ($request['$id'] === $requestId) {
                return $request;
            }
        }

        return null;
    }

    public function testUpdateRequest(): void
    {
        $requester = uniqid('octocat-');
        $requestId = $this->seedRequest($requester);

        $request = $this->findRequest($requestId);
        $this->assertEquals('requested', $request['status'] ?? '');

        // Confirming before the provider approved must fail.
        $early = $this->client->call(Client::METHOD_PATCH, '/vcs/requests/' . $requestId, $this->getRequestHeaders());
        $this->assertEquals(400, $early['headers']['status-code']);
        $this->assertEquals('installation_request_not_ready', $early['body']['type']);

        $this->approveOnProvider($requester, 424242, 'request-test-org');

        $request = $this->findRequest($requestId);
        $this->assertEquals('ready', $request['status'] ?? '');
        $this->assertEquals('request-test-org', $request['organization'] ?? '');

        $confirmed = $this->client->call(Client::METHOD_PATCH, '/vcs/requests/' . $requestId, $this->getRequestHeaders());
        $this->assertEquals(200, $confirmed['headers']['status-code']);
        $this->assertEquals('github', $confirmed['body']['provider']);
        $this->assertEquals('request-test-org', $confirmed['body']['organization']);
        $this->assertSame('424242', $confirmed['body']['providerInstallationId']);

        $installations = $this->client->call(Client::METHOD_GET, '/vcs/installations', $this->getRequestHeaders());
        $this->assertEquals(200, $installations['headers']['status-code']);
        $organizations = array_column($installations['body']['installations'], 'organization');
        $this->assertContains('request-test-org', $organizations);

        // Confirming consumes the request.
        $this->assertNull($this->findRequest($requestId));
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

        $foreign = $this->client->call(Client::METHOD_DELETE, '/vcs/requests/' . $requestId, $this->getRequestHeaders($foreignProject));
        $this->assertEquals(404, $foreign['headers']['status-code']);

        $deleted = $this->client->call(Client::METHOD_DELETE, '/vcs/requests/' . $requestId, $this->getRequestHeaders());
        $this->assertEquals(204, $deleted['headers']['status-code']);
    }

    public function testDeleteRequest(): void
    {
        $requestId = $this->seedRequest(uniqid('octocat-'));

        $deleted = $this->client->call(Client::METHOD_DELETE, '/vcs/requests/' . $requestId, $this->getRequestHeaders());
        $this->assertEquals(204, $deleted['headers']['status-code']);

        $missing = $this->client->call(Client::METHOD_PATCH, '/vcs/requests/' . $requestId, $this->getRequestHeaders());
        $this->assertEquals(404, $missing['headers']['status-code']);
    }

    public function testUpdateRequestIgnoresUnrelatedApproval(): void
    {
        $requester = uniqid('octocat-');
        $requestId = $this->seedRequest($requester);

        // No requester in the payload: a direct owner install, not an approval.
        $this->postInstallationEvent([
            'action' => 'created',
            'installation' => [
                'id' => 424244,
                'account' => ['login' => 'request-test-org3'],
            ],
        ]);

        // An approval for someone else's request.
        $this->postInstallationEvent([
            'action' => 'created',
            'installation' => [
                'id' => 424245,
                'account' => ['login' => 'request-test-org4'],
            ],
            'requester' => ['login' => uniqid('someone-else-')],
        ]);

        $request = $this->findRequest($requestId);
        $this->assertEquals('requested', $request['status'] ?? '');

        $deleted = $this->client->call(Client::METHOD_DELETE, '/vcs/requests/' . $requestId, $this->getRequestHeaders());
        $this->assertEquals(204, $deleted['headers']['status-code']);
    }

    public function testDeleteRequestOnProviderUninstall(): void
    {
        $requester = uniqid('octocat-');
        $requestId = $this->seedRequest($requester);
        $this->approveOnProvider($requester, 424246, 'request-test-org5');

        $this->postInstallationEvent([
            'action' => 'deleted',
            'installation' => [
                'id' => 424246,
                'account' => ['login' => 'request-test-org5'],
            ],
        ]);

        $this->assertNull($this->findRequest($requestId));
    }
}
