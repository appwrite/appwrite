<?php

declare(strict_types=1);

namespace Tests\E2E\General;

use Appwrite\Extend\Exception;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Id;
use Utopia\Database\Query;

/**
 * X-Appwrite-Reason: why the caller made a request, kept on its audit log.
 */
final class ReasonTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    public function testCreateWithReason(): void
    {
        /**
         * Test for SUCCESS
         */
        $team = $this->createTeam('Support', 'Support team for the storefront launch');
        $this->assertSame(201, $team['headers']['status-code']);

        // Percent-encoded UTF-8, for clients that cannot send non-ASCII header bytes.
        $team = $this->createTeam('Podpora', \rawurlencode('Tým podpory pro spuštění obchodu'));
        $this->assertSame(201, $team['headers']['status-code']);

        $team = $this->createTeam('Longest', \str_repeat('a', APP_LIMIT_AUDIT_REASON_LENGTH));
        $this->assertSame(201, $team['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $name = 'Rejected ' . Id::unique();

        $team = $this->createTeam($name, \str_repeat('a', APP_LIMIT_AUDIT_REASON_LENGTH + 1));
        $this->assertSame(400, $team['headers']['status-code']);
        $this->assertSame(Exception::GENERAL_ARGUMENT_INVALID, $team['body']['type']);

        $team = $this->createTeam($name, '%FF%FE');
        $this->assertSame(400, $team['headers']['status-code']);
        $this->assertSame(Exception::GENERAL_ARGUMENT_INVALID, $team['body']['type']);

        // A rejected reason stops the request before it runs.
        $teams = $this->client->call(Client::METHOD_GET, '/teams', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::equal('name', [$name])->toString()],
        ]);
        $this->assertSame(200, $teams['headers']['status-code']);
        $this->assertSame(0, $teams['body']['total']);
    }

    private function createTeam(string $name, string $reason): array
    {
        return $this->client->call(Client::METHOD_POST, '/teams', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-reason' => $reason,
        ], $this->getHeaders()), [
            'teamId' => Id::unique(),
            'name' => $name,
        ]);
    }
}
