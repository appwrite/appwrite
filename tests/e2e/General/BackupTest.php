<?php

namespace Tests\E2E\General;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Tests\E2E\Services\Functions\FunctionsBase;
use Utopia\Database\Query;

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

    protected function getConsoleHeadersGet(): array
    {
        return [
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
        $database = $this->client->call(
            Client::METHOD_GET,
            '/project/backups-policy/notfound',
            $this->getConsoleHeaders()
        );

        $this->assertEquals(404, $database['headers']['status-code']);

        $policy = $this->client->call(
            Client::METHOD_GET,
            '/project/backups-policy/policy1',
            $this->getConsoleHeadersGet()
        );

        $this->assertEquals(200, $policy['headers']['status-code']);
        $this->assertEquals('policy1', $policy['body']['$id']);
        $this->assertEquals('Hourly Backups', $policy['body']['name']);
        $this->assertEquals(true, $policy['body']['enabled']);

        /**
         * Test for update Policy
         */
        $policy = $this->client->call(
            Client::METHOD_PATCH,
            '/project/backups-policy/policy1',
            $this->getConsoleHeaders(),
            [
                'name' => 'Daily backups',
                'enabled' => false,
                'retention' => 10,
                'hours' => 3
            ]
        );

        $policyId = $policy['body']['$id'];

        $this->assertEquals(200, $policy['headers']['status-code']);
        $this->assertEquals('policy1', $policy['body']['$id']);
        $this->assertEquals('Daily backups', $policy['body']['name']);
        $this->assertEquals(false, $policy['body']['enabled']);

        /**
         * Test create Second policy
         */
        $response = $this->client->call(
            Client::METHOD_POST,
            '/project/backups-policy',
            $this->getConsoleHeaders(),
            [
                'policyId' => 'my-policy',
                'name' => 'New Hourly Backups',
                'enabled' => true,
                'retention' => 1,
                'hours' => 1,
            ]
        );

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertEquals('New Hourly Backups', $response['body']['name']);
        $this->assertEquals('my-policy', $response['body']['$id']);
        $this->assertEquals(1, $response['body']['hours']);
        $this->assertEquals(1, $response['body']['retention']);
        $this->assertEquals($this->getProject()['$id'], $response['body']['resourceId']);
        $this->assertEquals(true, $response['body']['enabled']);
        $this->assertEquals('backup-project', $response['body']['resourceType']);

        /**
         * Test get backup policies list
         */
        $policies = $this->client->call(
            Client::METHOD_GET,
            '/project/backups-policy',
            $this->getConsoleHeadersGet(),
            [
                'queries' => [
                    Query::orderDesc()->toString()
                ]
            ]
        );
        $this->assertEquals(200, $policies['headers']['status-code']);
        $this->assertEquals(2, count($policies['body']['backupPolicies']));

        /**
         * Test Delete policy
         */
        $response = $this->client->call(
            Client::METHOD_DELETE,
            "/project/backups-policy/{$policyId}",
            $this->getConsoleHeaders(),
            $this->getHeaders()
        );

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEquals('', $response['body']);

        /**
         * Test get backup policies list after delete
         */
        $policies = $this->client->call(
            Client::METHOD_GET,
            '/project/backups-policy',
            $this->getConsoleHeadersGet(),
            [
                'queries' => [
                    Query::orderDesc()->toString()
                ]
            ]
        );
        $this->assertEquals(200, $policies['headers']['status-code']);
        $this->assertEquals(1, count($policies['body']['backupPolicies']));
    }

    public function tearDown(): void
    {
        $this->projectId = '';
    }
}
