<?php

declare(strict_types=1);

namespace Tests\E2E\Services\VCSGitea;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;

/**
 * Cross-tenant isolation of VCS installations. Two unrelated console users with
 * their own teams, projects and Gitea accounts must not be able to use each
 * other's installations, even knowing the installation id.
 *
 * ProjectCustom and SideConsole only satisfy the Scope contract; every call in
 * this class authenticates as one of the two tenants built per test, never as
 * the shared root user.
 */
final class VCSGiteaInstallationsConsoleClientTest extends Scope
{
    use VCSGiteaBase;
    use ProjectCustom;
    use SideConsole;

    private const GITEA_USERNAME_SECOND = 'appwrite2';

    /**
     * @param array{projectId: string, session: string} $tenant
     * @return array<string, string>
     */
    private function getTenantHeaders(array $tenant, ?string $projectId = null): array
    {
        return [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $tenant['session'],
            'x-appwrite-mode' => 'admin',
            'x-appwrite-project' => $projectId ?? $tenant['projectId'],
        ];
    }

    public function testListRepositoriesRejectsForeignInstallation(): void
    {
        $this->createGiteaUserHelper(self::GITEA_USERNAME_SECOND, self::GITEA_PASSWORD);

        $a = $this->createTenantHelper();
        $b = $this->createTenantHelper();

        $installationA = $this->createInstallationHelper($a['projectId'], $a['headers']);
        $installationB = $this->createInstallationHelper($b['projectId'], $b['headers'], self::GITEA_USERNAME_SECOND, self::GITEA_PASSWORD);

        $this->assertNotEquals($installationA['$id'], $installationB['$id']);
        // Different Gitea accounts, so a cross-tenant leak would be visible as
        // the other owner's repositories, not a second identical listing.
        $this->assertNotEquals($installationA['organization'], $installationB['organization']);

        // Foreign installation id on the caller's own project never reaches the
        // provider: the ownership guard answers before any Gitea call.
        $foreign = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationA['$id'] . '/providerRepositories', $this->getTenantHeaders($b), [
            'type' => 'runtime',
        ]);
        $this->assertEquals(404, $foreign['headers']['status-code']);
        $this->assertEquals('installation_not_found', $foreign['body']['type']);

        $foreign = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationB['$id'] . '/providerRepositories', $this->getTenantHeaders($a), [
            'type' => 'runtime',
        ]);
        $this->assertEquals(404, $foreign['headers']['status-code']);
        $this->assertEquals('installation_not_found', $foreign['body']['type']);

        // Addressing the other tenant's project directly dies earlier, on team
        // membership, before any VCS code runs.
        $foreignProject = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationA['$id'] . '/providerRepositories', $this->getTenantHeaders($b, $a['projectId']), [
            'type' => 'runtime',
        ]);
        $this->assertEquals(401, $foreignProject['headers']['status-code']);

        // Each tenant still reaches their own installation.
        $own = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationA['$id'] . '/providerRepositories', $this->getTenantHeaders($a), [
            'type' => 'runtime',
        ]);
        $this->assertEquals(200, $own['headers']['status-code']);

        $own = $this->client->call(Client::METHOD_GET, '/vcs/github/installations/' . $installationB['$id'] . '/providerRepositories', $this->getTenantHeaders($b), [
            'type' => 'runtime',
        ]);
        $this->assertEquals(200, $own['headers']['status-code']);
    }

    public function testRepositoryEndpointsRejectForeignInstallation(): void
    {
        $a = $this->createTenantHelper();
        $b = $this->createTenantHelper();

        $installationA = $this->createInstallationHelper($a['projectId'], $a['headers']);
        $base = '/vcs/github/installations/' . $installationA['$id'];

        // Dummy repository ids are safe: the ownership guard answers before any
        // provider call. The POSTs would write to tenant A's Gitea account if
        // the guard regressed, which is exactly the point.
        $probes = [
            [Client::METHOD_GET, $base . '/providerRepositories/1', []],
            [Client::METHOD_GET, $base . '/providerRepositories/1/branches', []],
            [Client::METHOD_GET, $base . '/providerRepositories/1/contents', []],
            [Client::METHOD_POST, $base . '/providerRepositories', ['name' => 'cross-' . \uniqid(), 'private' => true]],
            [Client::METHOD_POST, $base . '/detections', ['providerRepositoryId' => '1', 'type' => 'runtime']],
        ];

        foreach ($probes as [$method, $path, $params]) {
            $response = $this->client->call($method, $path, $this->getTenantHeaders($b), $params);

            $this->assertEquals(404, $response['headers']['status-code'], $method . ' ' . $path);
            $this->assertEquals('installation_not_found', $response['body']['type'], $method . ' ' . $path);
        }
    }

    public function testInvitedMemberCanUseInstallation(): void
    {
        $a = $this->createTenantHelper();
        $b = $this->createTenantHelper();

        $installationA = $this->createInstallationHelper($a['projectId'], $a['headers']);
        $path = '/vcs/github/installations/' . $installationA['$id'] . '/providerRepositories';

        $before = $this->client->call(Client::METHOD_GET, $path, $this->getTenantHeaders($b, $a['projectId']), [
            'type' => 'runtime',
        ]);
        $this->assertEquals(401, $before['headers']['status-code']);

        $membership = $this->client->call(Client::METHOD_POST, '/teams/' . $a['teamId'] . '/memberships', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $a['session'],
        ], [
            'userId' => $b['userId'],
            'roles' => ['developer'],
            'url' => 'http://localhost:5000/join-us#title',
        ]);
        $this->assertEquals(201, $membership['headers']['status-code']);
        $this->assertFalse($membership['body']['confirm']);

        $email = $this->getLastEmailByAddress($b['email'], fn ($email) => $this->assertStringContainsString('/join-us', (string) ($email['html'] ?? '')));
        $params = $this->extractQueryParamsFromEmailLink($email['html']);
        $this->assertNotEmpty($params['secret'] ?? '');

        $accept = $this->client->call(Client::METHOD_PATCH, '/teams/' . $a['teamId'] . '/memberships/' . $membership['body']['$id'] . '/status', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'userId' => $b['userId'],
            'secret' => $params['secret'],
        ]);
        $this->assertEquals(200, $accept['headers']['status-code']);
        $this->assertTrue($accept['body']['confirm']);

        // Same request as before: membership in the team now authorizes B on
        // project A, and the installation belongs to project A, so it works.
        $after = $this->client->call(Client::METHOD_GET, $path, $this->getTenantHeaders($b, $a['projectId']), [
            'type' => 'runtime',
        ]);
        $this->assertEquals(200, $after['headers']['status-code']);

        // Membership does not launder the installation into B's own project.
        $stillForeign = $this->client->call(Client::METHOD_GET, $path, $this->getTenantHeaders($b), [
            'type' => 'runtime',
        ]);
        $this->assertEquals(404, $stillForeign['headers']['status-code']);
        $this->assertEquals('installation_not_found', $stillForeign['body']['type']);
    }
}
