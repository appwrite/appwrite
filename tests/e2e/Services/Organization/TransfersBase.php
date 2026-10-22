<?php

namespace Tests\E2E\Services\Organization;

use Appwrite\Extend\Exception;
use Tests\E2E\Client;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Query;
use Utopia\System\System;

trait TransfersBase
{
    private static string $ownerTeamId = '';
    private static array $claimer = [];

    /**
     * Console session headers of the root user, who owns the projects being transferred.
     */
    protected function getConsoleHeaders(): array
    {
        $headers = $this->getHeaders();
        unset($headers['x-appwrite-mode']);

        return $headers;
    }

    protected function getOwnerHeaders(): array
    {
        if (self::$ownerTeamId === '') {
            $team = $this->createTeamFixture(array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => 'console',
            ], $this->getConsoleHeaders()), [
                'teamId' => Id::unique(),
                'name' => 'Transfer Owner',
            ]);
            $this->assertSame(200, $team['headers']['status-code']);
            self::$ownerTeamId = $team['body']['$id'];
        }

        return array_merge($this->getConsoleHeaders(), [
            'x-appwrite-organization' => self::$ownerTeamId,
        ]);
    }

    /**
     * A second console user with their own organization, who has no access to the owner's.
     *
     * @return array{teamId: string, headers: array<string, string>}
     */
    protected function getClaimer(): array
    {
        if (self::$claimer === []) {
            $user = $this->getUser(true);
            $session = ['cookie' => 'a_session_console=' . $user['session']];

            $team = $this->createTeamFixture(array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => 'console',
            ], $session), [
                'teamId' => Id::unique(),
                'name' => 'Transfer Claimer',
            ]);
            $this->assertSame(200, $team['headers']['status-code']);

            self::$claimer = [
                'teamId' => $team['body']['$id'],
                'headers' => array_merge($session, [
                    'x-appwrite-organization' => $team['body']['$id'],
                ]),
            ];
        }

        return self::$claimer;
    }

    protected function createOwnerProject(): string
    {
        $project = $this->client->call(Client::METHOD_POST, '/organization/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], $this->getOwnerHeaders()), [
            'projectId' => Id::unique(),
            'name' => 'Transfer Project',
            'region' => System::getEnv('_APP_REGION', 'default'),
        ]);
        $this->assertSame(201, $project['headers']['status-code']);

        return $project['body']['$id'];
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function createTransfer(string $projectId, array $params = []): array
    {
        return $this->client->call(Client::METHOD_POST, '/organization/transfers', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], $this->getOwnerHeaders()), array_merge(['projectId' => $projectId], $params));
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $params
     */
    protected function callTransfers(string $method, string $path, array $headers, array $params = []): array
    {
        return $this->client->call($method, '/organization/transfers' . $path, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], $headers), $params);
    }

    public function testCreateProjectTransfer(): void
    {
        $projectId = $this->createOwnerProject();

        /**
         * Test for SUCCESS
         */
        $transfer = $this->createTransfer($projectId);

        $this->assertSame(201, $transfer['headers']['status-code']);
        $this->assertNotEmpty($transfer['body']['$id']);
        $this->assertSame('projects', $transfer['body']['resourceType']);
        $this->assertSame($projectId, $transfer['body']['resourceId']);
        $this->assertSame('Transfer Project', $transfer['body']['resourceName']);
        $this->assertSame('teams', $transfer['body']['senderType']);
        $this->assertSame(self::$ownerTeamId, $transfer['body']['senderId']);
        $this->assertSame('teams', $transfer['body']['recipientType']);
        $this->assertSame('', $transfer['body']['recipientId']);
        $this->assertSame(256, \strlen($transfer['body']['secret']));

        // Defaults to 24 hours, so it must end between 23 and 25 hours from now
        $expire = new \DateTime($transfer['body']['expire']);
        $this->assertGreaterThan(new \DateTime('+23 hours'), $expire);
        $this->assertLessThan(new \DateTime('+25 hours'), $expire);

        $restricted = $this->createTransfer($projectId, [
            'recipientId' => $this->getClaimer()['teamId'],
            'expire' => DateTime::addSeconds(new \DateTime(), 3600),
        ]);
        $this->assertSame(201, $restricted['headers']['status-code']);
        $this->assertSame($this->getClaimer()['teamId'], $restricted['body']['recipientId']);

        /**
         * Test for FAILURE
         */
        $response = $this->callTransfers(Client::METHOD_POST, '', $this->getConsoleHeaders(), ['projectId' => $projectId]);
        $this->assertSame(401, $response['headers']['status-code']);

        $response = $this->createTransfer('missing');
        $this->assertSame(404, $response['headers']['status-code']);
        $this->assertSame(Exception::PROJECT_NOT_FOUND, $response['body']['type']);

        // The claimer's organization can't give away the owner's project
        $response = $this->callTransfers(Client::METHOD_POST, '', $this->getClaimer()['headers'], ['projectId' => $projectId]);
        $this->assertSame(404, $response['headers']['status-code']);
        $this->assertSame(Exception::PROJECT_NOT_FOUND, $response['body']['type']);

        $response = $this->createTransfer($projectId, ['recipientId' => self::$ownerTeamId]);
        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame(Exception::TRANSFER_ALREADY_OWNED, $response['body']['type']);

        $response = $this->createTransfer($projectId, ['recipientId' => 'missing']);
        $this->assertSame(404, $response['headers']['status-code']);
        $this->assertSame(Exception::TEAM_NOT_FOUND, $response['body']['type']);

        $response = $this->createTransfer($projectId, ['expire' => DateTime::addSeconds(new \DateTime(), 31 * 24 * 60 * 60)]);
        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame(Exception::GENERAL_ARGUMENT_INVALID, $response['body']['type']);

        $response = $this->createTransfer($projectId, ['expire' => DateTime::addSeconds(new \DateTime(), -60)]);
        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testListProjectTransfers(): void
    {
        $projectId = $this->createOwnerProject();
        $otherProjectId = $this->createOwnerProject();
        $first = $this->createTransfer($projectId);
        $this->createTransfer($projectId);
        $this->createTransfer($otherProjectId);

        /**
         * Test for SUCCESS
         */
        $response = $this->callTransfers(Client::METHOD_GET, '', $this->getOwnerHeaders(), ['projectId' => $projectId]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(2, $response['body']['total']);
        $this->assertCount(2, $response['body']['transfers']);
        foreach ($response['body']['transfers'] as $transfer) {
            $this->assertSame($projectId, $transfer['resourceId']);
            $this->assertSame('Transfer Project', $transfer['resourceName']);
            $this->assertSame('', $transfer['secret']);
        }

        $response = $this->callTransfers(Client::METHOD_GET, '', $this->getOwnerHeaders(), [
            'projectId' => $projectId,
            'queries' => [Query::limit(1)->toString(), Query::cursorAfter(new Document(['$id' => $first['body']['$id']]))->toString()],
        ]);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertCount(1, $response['body']['transfers']);
        $this->assertNotSame($first['body']['$id'], $response['body']['transfers'][0]['$id']);

        // Another organization only sees its own transfers
        $response = $this->callTransfers(Client::METHOD_GET, '', $this->getClaimer()['headers'], ['projectId' => $projectId]);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(0, $response['body']['total']);

        /**
         * Test for FAILURE
         */
        $response = $this->callTransfers(Client::METHOD_GET, '', $this->getOwnerHeaders(), [
            'queries' => [Query::equal('secret', ['anything'])->toString()],
        ]);
        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testGetProjectTransfer(): void
    {
        $projectId = $this->createOwnerProject();
        $transfer = $this->createTransfer($projectId);
        $transferId = $transfer['body']['$id'];
        $secret = $transfer['body']['secret'];

        /**
         * Test for SUCCESS
         */
        $response = $this->callTransfers(Client::METHOD_GET, '/' . $transferId, $this->getOwnerHeaders());
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame($projectId, $response['body']['resourceId']);
        $this->assertSame('', $response['body']['secret']);

        // Another organization can preview the transfer with its secret
        $response = $this->callTransfers(Client::METHOD_GET, '/' . $transferId, $this->getClaimer()['headers'], ['secret' => $secret]);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('Transfer Project', $response['body']['resourceName']);
        $this->assertSame('', $response['body']['secret']);

        /**
         * Test for FAILURE
         */
        $response = $this->callTransfers(Client::METHOD_GET, '/' . $transferId, $this->getClaimer()['headers']);
        $this->assertSame(404, $response['headers']['status-code']);
        $this->assertSame(Exception::TRANSFER_NOT_FOUND, $response['body']['type']);

        $response = $this->callTransfers(Client::METHOD_GET, '/' . $transferId, $this->getClaimer()['headers'], ['secret' => 'wrong']);
        $this->assertSame(401, $response['headers']['status-code']);
        $this->assertSame(Exception::TRANSFER_INVALID_SECRET, $response['body']['type']);

        $response = $this->callTransfers(Client::METHOD_GET, '/missing', $this->getOwnerHeaders());
        $this->assertSame(404, $response['headers']['status-code']);
        $this->assertSame(Exception::TRANSFER_NOT_FOUND, $response['body']['type']);
    }

    public function testDeleteProjectTransfer(): void
    {
        $projectId = $this->createOwnerProject();
        $transfer = $this->createTransfer($projectId);
        $transferId = $transfer['body']['$id'];

        /**
         * Test for FAILURE
         */
        $response = $this->callTransfers(Client::METHOD_DELETE, '/' . $transferId, $this->getClaimer()['headers']);
        $this->assertSame(404, $response['headers']['status-code']);
        $this->assertSame(Exception::TRANSFER_NOT_FOUND, $response['body']['type']);

        /**
         * Test for SUCCESS
         */
        $response = $this->callTransfers(Client::METHOD_DELETE, '/' . $transferId, $this->getOwnerHeaders());
        $this->assertSame(204, $response['headers']['status-code']);

        $response = $this->callTransfers(Client::METHOD_GET, '/' . $transferId, $this->getOwnerHeaders());
        $this->assertSame(404, $response['headers']['status-code']);

        // A deleted transfer can't be claimed
        $response = $this->callTransfers(Client::METHOD_PATCH, '/' . $transferId . '/status', $this->getClaimer()['headers'], [
            'secret' => $transfer['body']['secret'],
        ]);
        $this->assertSame(404, $response['headers']['status-code']);
        $this->assertSame(Exception::TRANSFER_NOT_FOUND, $response['body']['type']);
    }

    public function testUpdateProjectTransferStatus(): void
    {
        $projectId = $this->createOwnerProject();
        $transfer = $this->createTransfer($projectId);
        $other = $this->createTransfer($projectId);
        $transferId = $transfer['body']['$id'];
        $secret = $transfer['body']['secret'];
        $claimer = $this->getClaimer();

        /**
         * Test for FAILURE
         */
        $response = $this->callTransfers(Client::METHOD_PATCH, '/' . $transferId . '/status', $claimer['headers'], ['secret' => 'wrong']);
        $this->assertSame(401, $response['headers']['status-code']);
        $this->assertSame(Exception::TRANSFER_INVALID_SECRET, $response['body']['type']);

        $response = $this->callTransfers(Client::METHOD_PATCH, '/' . $transferId . '/status', $this->getOwnerHeaders(), ['secret' => $secret]);
        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame(Exception::TRANSFER_ALREADY_OWNED, $response['body']['type']);

        // The claimer's session doesn't belong to the owner's organization
        $response = $this->callTransfers(Client::METHOD_PATCH, '/' . $transferId . '/status', array_merge($claimer['headers'], [
            'x-appwrite-organization' => self::$ownerTeamId,
        ]), ['secret' => $secret]);
        $this->assertSame(401, $response['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $response = $this->callTransfers(Client::METHOD_PATCH, '/' . $transferId . '/status', $claimer['headers'], ['secret' => $secret]);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame($projectId, $response['body']['$id']);
        $this->assertSame($claimer['teamId'], $response['body']['teamId']);

        $project = $this->client->call(Client::METHOD_GET, '/organization/projects/' . $projectId, array_merge([
            'x-appwrite-project' => 'console',
        ], $claimer['headers']));
        $this->assertSame(200, $project['headers']['status-code']);
        $this->assertSame($claimer['teamId'], $project['body']['teamId']);

        $project = $this->client->call(Client::METHOD_GET, '/organization/projects/' . $projectId, array_merge([
            'x-appwrite-project' => 'console',
        ], $this->getOwnerHeaders()));
        $this->assertSame(404, $project['headers']['status-code']);

        // The transfer is single use, and the previous owner's other links stop working
        $response = $this->callTransfers(Client::METHOD_PATCH, '/' . $transferId . '/status', $claimer['headers'], ['secret' => $secret]);
        $this->assertSame(404, $response['headers']['status-code']);
        $this->assertSame(Exception::TRANSFER_NOT_FOUND, $response['body']['type']);

        $response = $this->callTransfers(Client::METHOD_GET, '/' . $other['body']['$id'], $claimer['headers'], ['secret' => $other['body']['secret']]);
        $this->assertSame(404, $response['headers']['status-code']);

        // The new owner can transfer the project onwards
        $response = $this->callTransfers(Client::METHOD_POST, '', $claimer['headers'], ['projectId' => $projectId]);
        $this->assertSame(201, $response['headers']['status-code']);
    }

    public function testProjectTransferRecipient(): void
    {
        $projectId = $this->createOwnerProject();
        $claimer = $this->getClaimer();

        $third = $this->createTeamFixture(array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], $this->getConsoleHeaders()), [
            'teamId' => Id::unique(),
            'name' => 'Transfer Third',
        ]);
        $this->assertSame(200, $third['headers']['status-code']);

        $transfer = $this->createTransfer($projectId, ['recipientId' => $third['body']['$id']]);
        $this->assertSame(201, $transfer['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $response = $this->callTransfers(Client::METHOD_GET, '/' . $transfer['body']['$id'], $claimer['headers'], ['secret' => $transfer['body']['secret']]);
        $this->assertSame(403, $response['headers']['status-code']);
        $this->assertSame(Exception::TRANSFER_RECIPIENT_MISMATCH, $response['body']['type']);

        $response = $this->callTransfers(Client::METHOD_PATCH, '/' . $transfer['body']['$id'] . '/status', $claimer['headers'], ['secret' => $transfer['body']['secret']]);
        $this->assertSame(403, $response['headers']['status-code']);
        $this->assertSame(Exception::TRANSFER_RECIPIENT_MISMATCH, $response['body']['type']);

        /**
         * Test for SUCCESS
         */
        $response = $this->callTransfers(Client::METHOD_PATCH, '/' . $transfer['body']['$id'] . '/status', array_merge($this->getConsoleHeaders(), [
            'x-appwrite-organization' => $third['body']['$id'],
        ]), ['secret' => $transfer['body']['secret']]);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame($third['body']['$id'], $response['body']['teamId']);
    }

    public function testProjectTransferExpired(): void
    {
        $projectId = $this->createOwnerProject();
        $transfer = $this->createTransfer($projectId, [
            'expire' => DateTime::addSeconds(new \DateTime(), 2),
        ]);
        $this->assertSame(201, $transfer['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        // Waits on the preview, since a claim before the expiration would succeed and move the project
        $this->assertEventually(function () use ($transfer) {
            $response = $this->callTransfers(Client::METHOD_GET, '/' . $transfer['body']['$id'], $this->getClaimer()['headers'], [
                'secret' => $transfer['body']['secret'],
            ]);
            $this->assertSame(410, $response['headers']['status-code']);
            $this->assertSame(Exception::TRANSFER_EXPIRED, $response['body']['type']);
        }, 10_000, 500);

        $response = $this->callTransfers(Client::METHOD_PATCH, '/' . $transfer['body']['$id'] . '/status', $this->getClaimer()['headers'], [
            'secret' => $transfer['body']['secret'],
        ]);
        $this->assertSame(410, $response['headers']['status-code']);
        $this->assertSame(Exception::TRANSFER_EXPIRED, $response['body']['type']);

        $project = $this->client->call(Client::METHOD_GET, '/organization/projects/' . $projectId, array_merge([
            'x-appwrite-project' => 'console',
        ], $this->getOwnerHeaders()));
        $this->assertSame(200, $project['headers']['status-code']);
    }
}
