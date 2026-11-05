<?php

declare(strict_types=1);

namespace Tests\Unit\Event\Message;

use Appwrite\Event\Certificate as CertificateEvent;
use Appwrite\Event\Message\Certificate as CertificateMessage;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

require_once __DIR__ . '/../../../../app/init.php';

final class CertificateTest extends TestCase
{
    public function testSkipDomainValidationRoundTripsThroughTheQueuePayload(): void
    {
        $message = new CertificateMessage(
            project: new Document(['$id' => 'project-1', '$sequence' => '1']),
            domain: new Document(['domain' => 'www.example.com', 'domainType' => 'site']),
            action: CertificateEvent::ACTION_GENERATION,
            skipDomainValidation: true,
        );

        $restored = CertificateMessage::fromArray($message->toArray());

        $this->assertTrue($restored->skipDomainValidation);
        // The renew check is a separate decision and stays in place.
        $this->assertFalse($restored->skipRenewCheck);
        $this->assertSame('www.example.com', $restored->domain->getAttribute('domain'));
    }

    public function testAPayloadFromBeforeTheFlagStillValidatesTheDomain(): void
    {
        // Jobs already sitting in the queue when this ships carry no key.
        $restored = CertificateMessage::fromArray([
            'project' => ['$id' => 'project-1', '$sequence' => '1', 'database' => ''],
            'domain' => ['domain' => 'www.example.com', 'domainType' => 'site'],
            'skipRenewCheck' => false,
            'validationDomain' => null,
            'action' => CertificateEvent::ACTION_GENERATION,
        ]);

        $this->assertFalse($restored->skipDomainValidation);
    }
}
