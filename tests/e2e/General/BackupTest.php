<?php

namespace Tests\E2E\General;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Tests\E2E\Services\Functions\FunctionsBase;

class BackupTest extends Scope
{
    use ProjectCustom;
    use SideServer;
    use FunctionsBase;

    private const WAIT = 35;
    private const CREATE = 20;

    protected string $projectId;

    public function setUp(): void
    {
        parent::setUp();
    }

    protected static string $formatTz = 'Y-m-d\TH:i:s.vP';

    protected function getConsoleHeaders(): array
    {
        return [
            'content-type' => 'application/json',
            'origin' => 'http://localhost',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-mode' => 'admin'
        ];
    }

    public function testBackupPolicy(): void
    {
        /**
         * Test create new Backup policy
         */
        $response = $this->client->call(
            Client::METHOD_POST,
            '/project/backups-policy',
            $this->getConsoleHeaders(),
            [
                'policyId' => 'policy1',
                'name' => 'Hourly Backups',
                'enabled' => true,
                'retention' => 6,
                'hours' => 4,
            ]
        );

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertEquals('Hourly Backups', $response['body']['name']);
        $this->assertEquals('policy1', $response['body']['$id']);
        $this->assertEquals(4, $response['body']['hours']);
        $this->assertEquals(6, $response['body']['retention']);
        $this->assertEquals($this->getProject()['$id'], $response['body']['resourceId']);
        $this->assertEquals(true, $response['body']['enabled']);
        $this->assertEquals('backup-project', $response['body']['resourceType']);

        /**
         * Test for Duplicate
         */
        $duplicate = $this->client->call(
            Client::METHOD_POST,
            '/project/backups-policy',
            $this->getConsoleHeaders(),
            [
                'policyId' => 'policy1',
                'name' => 'Hourly Backups',
                'enabled' => true,
                'retention' => 6,
                'hours' => 4,
            ]
        );

        $this->assertEquals(409, $duplicate['headers']['status-code']);

        /**
         * Test for Policy not found
         */
        $database = $this->client->call(Client::METHOD_GET,
            '/project/backups-policy/notfound',
            [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(404, $database['headers']['status-code']);

        $this->assertEquals('---', '-------');

        $policy = $this->client->call(Client::METHOD_GET, '/databases/'. $databaseId .'/backups-policy/policy1', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $policy['headers']['status-code']);
        $this->assertEquals('policy1', $policy['body']['$id']);
        $this->assertEquals('Hourly Backups', $policy['body']['name']);
        $this->assertEquals(true, $policy['body']['enabled']);

        /**
         * Test for update Policy
         */
        $policy = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/backups-policy/policy1', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'name' => 'Daily backups',
            'enabled' => false,
            'retention' => 10,
            'hours' => 3,
        ]);

        $this->assertEquals(200, $policy['headers']['status-code']);
        $this->assertEquals('policy1', $policy['body']['$id']);
        $this->assertEquals('Daily backups', $policy['body']['name']);
        $this->assertEquals(false, $policy['body']['enabled']);

        $this->assertEquals('---', '-------');
    }

    public function tearDown(): void
    {
        $this->projectId = '';
    }
}
