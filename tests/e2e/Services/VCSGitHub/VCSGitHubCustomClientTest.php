<?php

declare(strict_types=1);

namespace Tests\E2E\Services\VCSGitHub;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;

final class VCSGitHubCustomClientTest extends Scope
{
    use VCSGitHubBase;
    use ProjectCustom;
    use SideClient;

    public function testGetInstallation(string $installationId = 'randomString'): void
    {
        $installation = $this->client->call(Client::METHOD_GET, '/vcs/installations/' . $installationId, array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(401, $installation['headers']['status-code']);
    }
}
