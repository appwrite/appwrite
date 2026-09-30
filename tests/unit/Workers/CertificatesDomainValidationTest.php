<?php

declare(strict_types=1);

namespace Tests\Unit\Workers;

use Appwrite\Event\Certificate as CertificateEvent;
use Appwrite\Event\Event;
use Appwrite\Event\Message\Certificate as CertificateMessage;
use Appwrite\Event\Publisher\Certificate as CertificatePublisher;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Publisher\Mail as MailPublisher;
use Appwrite\Event\Realtime;
use Appwrite\Event\Webhook;
use Appwrite\Platform\Workers\Certificates;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Event\MockPublisher;
use Utopia\Bus\Bus;
use Utopia\Cdn\Certificates\Provider;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;

require_once __DIR__ . '/../../../app/init.php';

/**
 * The proxy endpoints verify a domain's DNS before they enqueue generation.
 * The worker ran the same check again seconds later, and a transient failure
 * there wrote `unverified` over a status the endpoint had just written. The
 * domain here sits under a TLD no public suffix list knows, which the check
 * refuses before touching the network, so what the tests observe is whether
 * the check ran: a job that reaches issuance skipped it, a job that fails the
 * rule did not.
 */
#[AllowMockObjectsWithoutExpectations]
final class CertificatesDomainValidationTest extends TestCase
{
    private const DOMAIN = 'www.example.invalid';

    public function testAJobFromAVerifiedEndpointReachesIssuanceWithoutASecondDnsCheck(): void
    {
        $certificates = $this->createMock(Provider::class);
        $certificates->method('isRenewRequired')->willReturn(true);
        $certificates->method('isInstantGeneration')->willReturn(false);
        $certificates->expects($this->once())->method('issueCertificate');

        $writes = $this->generate($certificates, skipDomainValidation: true);

        $this->assertSame(RULE_STATUS_CERTIFICATE_GENERATING, $writes['rules']['status']);
    }

    public function testTheRenewCheckStaysInPlaceWhenOnlyDomainValidationIsSkipped(): void
    {
        // Unlike a forced job, this one still asks whether a renewal is needed.
        $certificates = $this->createMock(Provider::class);
        $certificates->method('isRenewRequired')->willReturn(false);
        $certificates->expects($this->never())->method('issueCertificate');

        $writes = $this->generate($certificates, skipDomainValidation: true);

        $this->assertSame(RULE_STATUS_CERTIFICATE_GENERATING, $writes['rules']['status']);
    }

    public function testAJobWithoutTheFlagStillChecksDns(): void
    {
        $certificates = $this->createMock(Provider::class);
        $certificates->expects($this->never())->method('issueCertificate');

        $writes = $this->generate($certificates, skipDomainValidation: false);

        $this->assertSame(RULE_STATUS_CERTIFICATE_GENERATION_FAILED, $writes['rules']['status']);
    }

    /**
     * Runs one generation job for a rule in `verifying`.
     *
     * @return array<string, array<string, mixed>> the attributes written per collection
     */
    private function generate(Provider&Stub $certificates, bool $skipDomainValidation): array
    {
        $rule = new Document([
            '$id' => md5(self::DOMAIN),
            '$collection' => 'rules',
            'domain' => self::DOMAIN,
            'type' => 'deployment',
            'deploymentResourceType' => 'site',
            'status' => RULE_STATUS_CERTIFICATE_GENERATING,
            'owner' => '',
            'certificateId' => 'cert-1',
            'projectId' => 'project-1',
            'projectInternalId' => '1',
        ]);

        $certificate = new Document([
            '$id' => 'cert-1',
            '$collection' => 'certificates',
            '$updatedAt' => DateTime::now(),
            'domain' => self::DOMAIN,
            'attempts' => 0,
            'logs' => '',
        ]);

        $writes = [];

        $dbForPlatform = $this->createStub(Database::class);
        $dbForPlatform->method('getDocument')
            ->willReturnCallback(fn (string $collection, string $id): Document => match ($collection) {
                'rules' => $rule,
                'certificates' => $certificate,
                // No project row: the event fan-out stops there.
                default => new Document(),
            });
        $dbForPlatform->method('findOne')->willReturn($rule);
        $dbForPlatform->method('updateDocument')
            ->willReturnCallback(function (string $collection, string $id, Document $document) use (&$writes): Document {
                $writes[$collection] = $document->getArrayCopy();

                return new Document(\array_merge(['$id' => $id], $document->getArrayCopy()));
            });

        $message = (new Message())->setPayload((new CertificateMessage(
            project: new Document(['$id' => 'project-1', '$sequence' => '1']),
            domain: new Document(['domain' => self::DOMAIN, 'domainType' => 'site']),
            action: CertificateEvent::ACTION_GENERATION,
            skipDomainValidation: $skipDomainValidation,
        ))->toArray());

        try {
            (new Certificates())->action(
                $message,
                $dbForPlatform,
                new MailPublisher(new MockPublisher(), new Queue('v1-mails')),
                $this->createStub(Event::class),
                $this->createStub(Webhook::class),
                new FunctionPublisher(new MockPublisher(), new Queue('v1-functions')),
                $this->createStub(Realtime::class),
                new CertificatePublisher(new MockPublisher(), new Queue('v1-certificates')),
                $certificates,
                [],
                $this->createStub(Authorization::class),
                (new Bus())->setResolver(static fn (): null => null),
            );
        } catch (\Throwable) {
            // The worker rethrows an issuance failure after recording it; the
            // records are what these tests read.
        }

        return $writes;
    }
}
