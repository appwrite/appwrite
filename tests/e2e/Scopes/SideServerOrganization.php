<?php

namespace Tests\E2E\Scopes;

use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;

trait SideServerOrganization
{
    /**
     * @var array
     */
    protected static array $organization = [];

    protected function getOrganization(): array
    {
        if (!empty(self::$organization)) {
            return self::$organization;
        }

        $team = null;
        $teamId = ID::unique();
        for ($i = 0; $i < 3; $i++) {
            $team = $this->client->call(Client::METHOD_POST, '/teams', [
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'cookie' => 'a_session_console=' . $this->getRoot()['session'],
                'x-appwrite-project' => 'console',
            ], [
                'teamId' => $teamId,
                'name' => 'Organization Test',
            ]);
            if (\in_array($team['headers']['status-code'], [201, 409])) {
                break;
            }
            \usleep(500000);
        }
        $this->assertContains($team['headers']['status-code'], [201, 409]);
        $teamId = $team['body']['$id'] ?? $teamId;

        $key = $this->client->call(Client::METHOD_POST, '/v1/organization/keys', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
            'x-appwrite-organization' => $teamId,
        ], [
            'keyId' => ID::unique(),
            'name' => 'Organization Key',
            'scopes' => ['projects.read', 'projects.write'],
        ]);

        $this->assertEquals(201, $key['headers']['status-code']);
        $this->assertNotEmpty($key['body']['secret']);

        self::$organization = [
            '$id' => $teamId,
            'apiKey' => $key['body']['secret'],
        ];

        return self::$organization;
    }

    public function getHeaders(bool $devKey = false): array
    {
        $organization = $this->getOrganization();

        return [
            'x-appwrite-key' => $organization['apiKey'],
            'x-appwrite-organization' => $organization['$id'],
        ];
    }

    /**
     * @return string
     */
    public function getSide()
    {
        return 'server';
    }
}
