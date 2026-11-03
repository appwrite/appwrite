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
use PHPUnit\Framework\TestCase;
use Tests\Unit\Event\MockPublisher;
use Utopia\Bus\Bus;
use Utopia\Cdn\Certificates\Provider;
use Utopia\Cdn\Certificates\Status;
use Utopia\Cdn\Exception\Certificate as CertificateException;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;

require_once __DIR__ . '/../../../app/init.php';

/**
 * A delayed provider can be blocked on a DNS record only the domain owner can
 * add. On a new rule that is a wait the interval keeps checking, not a failed
 * attempt, and only a new or changed wait is announced. On an older rule each
 * check counts again, so an abandoned domain still ends unverified.
 */
#[AllowMockObjectsWithoutExpectations]
final class CertificatesBlockedTest extends TestCase
{
    private const DOMAIN = 'www.example.invalid';

    private const RECORD = 'Add a CNAME record named _acme-challenge.www.example.invalid with the value token.example.net to verify ownership, then wait for the certificate to be issued.';

    public function testANewRuleWaitsForTheDnsRecordWithoutUsingAnAttempt(): void
    {
        $result = $this->generate(Status::BLOCKED, createdAt: DateTime::now(), attempts: 4);

        $this->assertNull($result['error']);
        $this->assertSame(RULE_STATUS_CERTIFICATE_GENERATING, $result['rules']['status']);
        $this->assertSame(4, $result['certificates']['attempts']);
        $this->assertGreaterThan(DateTime::now(), $result['certificates']['renewDate']);
        $this->assertStringContainsString("\033[33mSSL certificate is waiting for a DNS record. " . self::RECORD, $result['rules']['logs']);
        $this->assertStringNotContainsString('failed', $result['rules']['logs']);
        $this->assertSame(0, $result['mails']);
        $this->assertSame(1, $result['webhooks']);
        $this->assertSame(1, $result['functions']);
    }

    public function testARepeatedWaitIsSavedWithoutAnnouncingItAgain(): void
    {
        $first = $this->generate(Status::BLOCKED, createdAt: DateTime::now(), attempts: 0);

        $second = $this->generate(Status::BLOCKED, createdAt: DateTime::now(), attempts: 0, logs: $first['rules']['logs']);

        $this->assertNull($second['error']);
        $this->assertSame(RULE_STATUS_CERTIFICATE_GENERATING, $second['rules']['status']);
        $this->assertStringContainsString('SSL certificate is waiting for a DNS record. ' . self::RECORD, $second['rules']['logs']);
        $this->assertSame(0, $second['webhooks']);
        $this->assertSame(0, $second['functions']);
    }

    public function testAChangedRecordIsAnnouncedAgain(): void
    {
        $first = $this->generate(Status::BLOCKED, createdAt: DateTime::now(), attempts: 0, record: 'Add a CNAME record named _acme-challenge.www.example.invalid with the value old-token.example.net to verify ownership, then wait for the certificate to be issued.');

        $second = $this->generate(Status::BLOCKED, createdAt: DateTime::now(), attempts: 0, logs: $first['rules']['logs']);

        $this->assertStringContainsString('SSL certificate is waiting for a DNS record. ' . self::RECORD, $second['rules']['logs']);
        $this->assertSame(1, $second['webhooks']);
        $this->assertSame(1, $second['functions']);
    }

    public function testAnOldRuleCountsTheWaitAsAFailedAttempt(): void
    {
        // The last failed attempt is mailed to this address
        $previous = getenv('_APP_EMAIL_CERTIFICATES');
        putenv('_APP_EMAIL_CERTIFICATES=certificates@example.com');

        try {
            $result = $this->generate(Status::BLOCKED, createdAt: DateTime::addSeconds(new \DateTime(), -4 * 24 * 60 * 60), attempts: 4);
        } finally {
            putenv($previous === false ? '_APP_EMAIL_CERTIFICATES' : '_APP_EMAIL_CERTIFICATES=' . $previous);
        }

        $this->assertInstanceOf(CertificateException::class, $result['error']);
        $this->assertSame(RULE_STATUS_CERTIFICATE_GENERATION_FAILED, $result['rules']['status']);
        $this->assertSame(5, $result['certificates']['attempts']);
        $this->assertStringContainsString('SSL certificate issuance failed', $result['rules']['logs']);
        $this->assertSame(1, $result['mails']);
    }

    public function testAProviderThatStoppedTryingUsesAnAttempt(): void
    {
        $result = $this->generate(Status::FAILED, createdAt: DateTime::now(), attempts: 0);

        $this->assertNull($result['error']);
        $this->assertSame(RULE_STATUS_CERTIFICATE_GENERATING, $result['rules']['status']);
        $this->assertSame(1, $result['certificates']['attempts']);
        $this->assertStringContainsString('SSL certificate issuance failed', $result['rules']['logs']);
    }

    /**
     * Runs one interval-queued generation job for a rule in `verifying` whose
     * provider reports the given status for the domain's certificate, with
     * the logs the rule's last check left.
     *
     * @return array{rules: array<string, mixed>, certificates: array<string, mixed>, error: ?\Throwable, mails: int, webhooks: int, functions: int}
     */
    private function generate(string $status, string $createdAt, int $attempts, string $logs = '', string $record = self::RECORD): array
    {
        $rule = new Document([
            '$id' => md5(self::DOMAIN),
            '$collection' => 'rules',
            '$createdAt' => $createdAt,
            'domain' => self::DOMAIN,
            'type' => 'deployment',
            'deploymentResourceType' => 'site',
            'status' => RULE_STATUS_CERTIFICATE_GENERATING,
            'owner' => '',
            'certificateId' => 'cert-1',
            'projectId' => 'project-1',
            'projectInternalId' => '1',
            'logs' => $logs,
        ]);

        $certificate = new Document([
            '$id' => 'cert-1',
            '$collection' => 'certificates',
            'domain' => self::DOMAIN,
            'attempts' => $attempts,
            'logs' => '',
        ]);

        $certificates = $this->createStub(Provider::class);
        $certificates->method('isRenewRequired')->willReturn(false);
        $certificates->method('isInstantGeneration')->willReturn(false);
        $certificates->method('getCertificateStatus')->willThrowException(new CertificateException($record, $status));

        $writes = [];

        $dbForPlatform = $this->createStub(Database::class);
        $dbForPlatform->method('getDocument')
            ->willReturnCallback(fn (string $collection, string $id): Document => match ($collection) {
                'rules' => $rule,
                'certificates' => $certificate,
                'projects' => new Document(['$id' => $id]),
                default => new Document(),
            });
        $dbForPlatform->method('findOne')->willReturn($rule);
        $dbForPlatform->method('updateDocument')
            ->willReturnCallback(function (string $collection, string $id, Document $document) use (&$writes, $rule, $certificate): Document {
                $writes[$collection] = $document->getArrayCopy();

                // Like the database, return the whole row with the update applied
                return new Document(\array_merge(($collection === 'rules' ? $rule : $certificate)->getArrayCopy(), $document->getArrayCopy()));
            });

        $mails = new MockPublisher();
        $events = new MockPublisher();

        $message = (new Message())->setPayload((new CertificateMessage(
            project: new Document(['$id' => 'project-1', '$sequence' => '1']),
            domain: new Document(['domain' => self::DOMAIN, 'domainType' => 'site']),
            action: CertificateEvent::ACTION_GENERATION,
            skipDomainValidation: true,
        ))->toArray());

        $error = null;
        try {
            (new Certificates())->action(
                $message,
                $dbForPlatform,
                new MailPublisher($mails, new Queue('v1-mails')),
                new Event($events),
                new Webhook($events),
                new FunctionPublisher($events, new Queue('v1-functions')),
                $this->createStub(Realtime::class),
                new CertificatePublisher(new MockPublisher(), new Queue('v1-certificates')),
                $certificates,
                [],
                $this->createStub(Authorization::class),
                (new Bus())->setResolver(static fn (): null => null),
            );
        } catch (\Throwable $th) {
            $error = $th;
        }

        return [
            'rules' => $writes['rules'],
            'certificates' => $writes['certificates'],
            'error' => $error,
            'mails' => \count($mails->getEvents('v1-mails') ?? []),
            'webhooks' => \count($events->getEvents(Event::WEBHOOK_QUEUE_NAME) ?? []),
            'functions' => \count($events->getEvents('v1-functions') ?? []),
        ];
    }
}
