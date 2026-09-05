<?php

declare(strict_types=1);

namespace Tests\Unit\Event\Message;

use Appwrite\Event\Context\Audit as AuditContext;
use Appwrite\Event\Message\Audit as AuditMessage;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

require_once __DIR__ . '/../../../../app/init.php';

final class AuditTest extends TestCase
{
    /**
     * The request context and the queue payload are separated by a serialize /
     * deserialize hop, so a field the context carries but `toArray()` omits is
     * dropped without any error — the worker just sees nothing. Walk the whole
     * hop the way the request lifecycle does: context → message → payload →
     * message.
     */
    public function testAuditMessageCarriesRequestFieldsAcrossTheQueue(): void
    {
        $context = new AuditContext(
            project: new Document(['$id' => 'project-1', '$sequence' => '1']),
            user: new Document(['$id' => 'user-1']),
            mode: APP_MODE_ADMIN,
            userAgent: 'Mozilla/5.0',
            ip: '8.8.8.8',
            hostname: 'sgp.cloud.appwrite.io',
            sdk: 'console',
            sdkVersion: '16.0.0',
            origin: 'https://cloud.appwrite.io',
            event: 'database.delete',
            resource: 'database/database-1',
        );

        $payload = AuditMessage::fromContext($context)->toArray();

        $this->assertSame('https://cloud.appwrite.io', $payload['origin']);
        $this->assertSame('sgp.cloud.appwrite.io', $payload['hostname']);
        $this->assertSame('console', $payload['sdk']);
        $this->assertSame(APP_MODE_ADMIN, $payload['mode']);

        $this->assertSame('https://cloud.appwrite.io', AuditMessage::fromArray($payload)->origin);
        $this->assertSame($payload, AuditMessage::fromArray($payload)->toArray());
    }

    /**
     * A caller that is not a browser sends no `Origin` header, and that absence
     * is the reason the field exists — it is what separates a console click
     * from a script replaying a console session cookie. It has to survive the
     * queue hop as an empty string rather than becoming a missing key, because
     * a consumer reading a missing key cannot tell "no origin" from "this build
     * does not send origin yet".
     */
    public function testAuditMessageKeepsAnAbsentOriginAsAnEmptyString(): void
    {
        $payload = AuditMessage::fromContext(new AuditContext(
            mode: APP_MODE_ADMIN,
            hostname: 'sgp.cloud.appwrite.io',
            event: 'database.delete',
            resource: 'database/database-1',
        ))->toArray();

        $this->assertArrayHasKey('origin', $payload);
        $this->assertSame('', $payload['origin']);
        $this->assertSame('', AuditMessage::fromArray($payload)->origin);
    }

    /**
     * `isEmpty()` decides whether the lifecycle publishes anything at all, so a
     * field it does not check makes a context that carries only that field look
     * empty and never reach the queue.
     */
    public function testContextCarryingOnlyAnOriginIsNotEmpty(): void
    {
        $this->assertTrue((new AuditContext())->isEmpty());
        $this->assertFalse((new AuditContext(origin: 'https://cloud.appwrite.io'))->isEmpty());
    }
}
