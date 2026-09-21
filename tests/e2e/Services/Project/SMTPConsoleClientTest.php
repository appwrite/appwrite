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

final class SMTPConsoleClientTest extends Scope
{
    use SMTPBase;
    use ProjectCustom;
    use SideConsole;
    use Async;

    public function testCreateSMTPTestAudit(): void
    {
        // Audit events are only published and queryable (via /activities/events)
        // in the cloud edition; the self-hosted publisher is a no-op.
        if (System::getEnv('_APP_EDITION', 'self-hosted') === 'self-hosted') {
            $this->markTestSkipped('Audit events are only recorded in the cloud edition.');
        }

        $headers = \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());

        $response = $this->client->call(Client::METHOD_POST, '/project/smtp/tests', $headers, [
            'emails' => ['audit-smtp-' . \uniqid() . '@appwrite.io'],
            'senderName' => 'Audit Sender',
            'senderEmail' => 'audit-sender@appwrite.io',
            'host' => 'maildev',
            'port' => 1025,
            'username' => 'user',
            'password' => 'password',
        ]);

        $this->assertSame(204, $response['headers']['status-code']);

        // The audit is written asynchronously by the audits worker.
        $this->assertEventually(function () use ($headers) {
            $events = $this->client->call(Client::METHOD_GET, '/activities/events', $headers, [
                'queries' => [
                    Query::equal('event', 'project.smtp.test')->toString(),
                ],
            ]);

            $this->assertSame(200, $events['headers']['status-code']);
            $this->assertNotEmpty($events['body']['events']);

            foreach ($events['body']['events'] as $event) {
                $this->assertSame('project.smtp.test', $event['event']);
                $this->assertSame('project/' . $this->getProject()['$id'], $event['resource']);
            }
        }, timeoutMs: 15000, waitMs: 500);
    }
}
