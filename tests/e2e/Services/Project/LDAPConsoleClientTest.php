<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Project;

use Appwrite\Tests\Async;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;
use Utopia\Audit\Query;
use Utopia\System\System;

final class LDAPConsoleClientTest extends Scope
{
    use LDAPBase;
    use ProjectCustom;
    use SideConsole;
    use Async;

    public function testUpdateLDAPAudit(): void
    {
        // Audit events are only published and queryable (via /activities/events)
        // in the cloud edition; the self-hosted publisher is a no-op.
        if (System::getEnv('_APP_EDITION', 'self-hosted') === 'self-hosted') {
            $this->markTestSkipped('Audit events are only recorded in the cloud edition.');
        }

        $response = $this->updateLDAP([
            'nameAttribute' => 'displayName',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);

        // The audit is written asynchronously by the audits worker.
        $this->assertEventually(function () {
            $events = $this->client->call(Client::METHOD_GET, '/activities/events', $this->projectHeaders(), [
                'queries' => [
                    Query::equal('event', 'project.ldap.update')->toString(),
                ],
            ]);

            $this->assertSame(200, $events['headers']['status-code']);
            $this->assertNotEmpty($events['body']['events']);

            foreach ($events['body']['events'] as $event) {
                $this->assertSame('project.ldap.update', $event['event']);
                $this->assertSame('project.ldap/' . $this->getProject()['$id'], $event['resource']);
            }
        }, timeoutMs: 15000, waitMs: 500);
    }
}
