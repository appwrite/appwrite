<?php

namespace Tests\E2E\Services\Advisor;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;

class AdvisorCustomServerTest extends Scope
{
    use AdvisorBase;
    use ProjectCustom;
    use SideServer;

    public function testReadWithAdvisorScopes(): void
    {
        $projectId = $this->getProject()['$id'];

        $userKey = $this->getNewKey([
            // Advisor read APIs are protected by the underlying report/insight resource scopes.
            'insights.read',
            'reports.read',
        ]);

        $listed = $this->client->call(
            Client::METHOD_GET,
            '/reports',
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $userKey,
            ]
        );

        $this->assertSame(200, $listed['headers']['status-code']);

        $create = $this->client->call(
            Client::METHOD_POST,
            '/reports',
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $userKey,
            ],
            [
                'reportId' => ID::unique(),
                'type' => 'audit',
                'title' => 'Read-only check',
                'targetType' => 'sites',
                'target' => 'home',
            ]
        );

        $this->assertSame(404, $create['headers']['status-code']);
    }
}
