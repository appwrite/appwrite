<?php

declare(strict_types=1);

namespace Tests\E2E\General\Certificates;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Certificate as CertificateMessage;
use Appwrite\Event\Publisher\Certificate as CertificatePublisher;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Publisher\Mail as MailPublisher;
use Appwrite\Event\Realtime;
use Appwrite\Event\Webhook;
use Appwrite\Platform\Workers\Certificates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Event\MockPublisher;
use Utopia\Bus\Bus;
use Utopia\Cdn\Certificates\Status;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;

final class WorkerTest extends TestCase
{
    private Database $database;
    private Provider $provider;
    private MockPublisher $publisher;
    private string|false $format;

    protected function setUp(): void
    {
        $this->format = getenv('_APP_RULES_FORMAT');
        putenv('_APP_RULES_FORMAT=md5');
        $this->database = new Database();
        $this->provider = new Provider();
        $this->publisher = new MockPublisher();
        $project = $this->database->createDocument('projects', new Document(['$id' => 'project']));
        $this->database->createDocument('certificates', new Document(['$id' => 'certificate', 'domain' => 'example.com', 'attempts' => 3]));
        $this->database->createDocument('rules', new Document([
            '$id' => md5('example.com'),
            'domain' => 'example.com',
            'type' => 'api',
            'region' => 'default',
            'projectId' => 'project',
            'projectInternalId' => $project->getSequence(),
            'certificateId' => 'certificate',
            'status' => RULE_STATUS_CERTIFICATE_GENERATING,
        ]));
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->delete();
        }
        putenv($this->format === false ? '_APP_RULES_FORMAT' : '_APP_RULES_FORMAT=' . $this->format);
    }

    public function testIssuedCertificateVerifiesTheRule(): void
    {
        /**
         * Test for SUCCESS
         */
        $this->provider->renew = false;
        $this->provider->status = Status::ISSUED;

        $this->runWorker();

        $this->assertSame([], $this->provider->issued);
        $this->assertSame(RULE_STATUS_VERIFIED, $this->rule()->getAttribute('status'));
        $this->assertSame(0, $this->certificate()->getAttribute('attempts'));
    }

    public function testValidInstantCertificateVerifiesTheRule(): void
    {
        /**
         * Test for SUCCESS
         */
        $this->provider->instant = true;
        $this->provider->renew = false;
        $this->provider->status = Status::UNKNOWN; // Instant providers never report a status

        $this->runWorker();

        $this->assertSame([], $this->provider->issued);
        $this->assertSame(RULE_STATUS_VERIFIED, $this->rule()->getAttribute('status'));
        $this->assertSame(0, $this->certificate()->getAttribute('attempts'));
    }

    #[DataProvider('inFlight')]
    public function testInFlightCertificateKeepsTheRuleGenerating(string $status): void
    {
        /**
         * Test for SUCCESS
         */
        $this->provider->renew = false;
        $this->provider->status = $status;

        $this->runWorker();

        $this->assertSame([], $this->provider->issued);
        $this->assertSame(RULE_STATUS_CERTIFICATE_GENERATING, $this->rule()->getAttribute('status'));
        $this->assertSame(3, $this->certificate()->getAttribute('attempts'));
    }

    public static function inFlight(): \Iterator
    {
        yield 'pending' => [Status::PENDING];
        yield 'processing' => [Status::PROCESSING];
        yield 'renewing' => [Status::RENEWING];
    }

    #[DataProvider('missing')]
    public function testMissingCertificateIsIssuedEvenWhenNoRenewalIsDue(string $status): void
    {
        /**
         * Test for SUCCESS
         */
        $this->provider->renew = false;
        $this->provider->status = $status;

        $this->runWorker();

        $this->assertSame([['example.com', 'api']], $this->provider->issued);
        $this->assertSame(RULE_STATUS_CERTIFICATE_GENERATING, $this->rule()->getAttribute('status'));
        $this->assertSame(0, $this->certificate()->getAttribute('attempts'));
    }

    public static function missing(): \Iterator
    {
        yield 'unknown' => [Status::UNKNOWN];
        yield 'failed' => [Status::FAILED];
    }

    private function runWorker(): void
    {
        $webhooks = $this->createStub(Webhook::class);
        $webhooks->method('from')->willReturnSelf();
        $realtime = $this->createStub(Realtime::class);
        $realtime->method('setSubscribers')->willReturnSelf();
        $realtime->method('from')->willReturnSelf();
        $message = new CertificateMessage(
            project: new Document(['$id' => 'project']),
            domain: new Document(['domain' => 'example.com', 'domainType' => 'api']),
            validationDomain: 'example.com',
        );
        (new Certificates())->action(
            (new Message())->setPayload($message->toArray()),
            $this->database,
            new MailPublisher($this->publisher, new Queue('mails')),
            new Event($this->publisher),
            $webhooks,
            new FunctionPublisher($this->publisher, new Queue('functions')),
            $realtime,
            new CertificatePublisher($this->publisher, new Queue('certificates')),
            $this->provider,
            [],
            new Authorization(),
            (new Bus())->setResolver(static fn () => null),
        );
    }

    private function rule(): Document
    {
        return $this->database->getDocument('rules', md5('example.com'));
    }

    private function certificate(): Document
    {
        return $this->database->getDocument('certificates', 'certificate');
    }
}
