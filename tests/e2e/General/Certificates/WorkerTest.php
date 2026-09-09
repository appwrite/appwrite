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
    private Event $events;
    private Bus $bus;
    private string|false $format;
    private string|false $email;
    private Document $project;

    protected function setUp(): void
    {
        $this->format = getenv('_APP_RULES_FORMAT');
        $this->email = getenv('_APP_EMAIL_CERTIFICATES');
        putenv('_APP_RULES_FORMAT=md5');
        putenv('_APP_EMAIL_CERTIFICATES=admin@example.com');
        $this->database = new Database();
        $this->provider = new Provider();
        $this->publisher = new MockPublisher();
        $this->events = new Event($this->publisher);
        $this->bus = (new Bus())->setResolver(static fn () => null);
        $this->project = $this->database->createDocument('projects', new Document(['$id' => 'project']));
        $this->database->createDocument('certificates', new Document(['$id' => 'certificate', 'domain' => 'example.com', 'attempts' => 0, 'updated' => null]));
        $this->database->createDocument('rules', new Document([
            '$id' => md5('example.com'),
            'domain' => 'example.com',
            'type' => 'api',
            'region' => 'default',
            'projectId' => 'project',
            'projectInternalId' => $this->project->getSequence(),
            'certificateId' => 'certificate',
            'status' => RULE_STATUS_CERTIFICATE_GENERATION_FAILED,
        ]));
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->delete();
        }
        putenv($this->format === false ? '_APP_RULES_FORMAT' : '_APP_RULES_FORMAT=' . $this->format);
        putenv($this->email === false ? '_APP_EMAIL_CERTIFICATES' : '_APP_EMAIL_CERTIFICATES=' . $this->email);
    }

    public function testIssuedCertificateVerifiesTheRule(): void
    {
        /**
         * Test for SUCCESS
         */
        $this->setRule(['status' => RULE_STATUS_CERTIFICATE_GENERATING]);
        $this->database->updateDocument('certificates', 'certificate', new Document(['attempts' => 3]));
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
        $this->setRule(['status' => RULE_STATUS_CERTIFICATE_GENERATING]);
        $this->database->updateDocument('certificates', 'certificate', new Document(['attempts' => 3]));
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
        $this->setRule(['status' => RULE_STATUS_CERTIFICATE_GENERATING]);
        $this->database->updateDocument('certificates', 'certificate', new Document(['attempts' => 3]));
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
        $this->setRule(['status' => RULE_STATUS_CERTIFICATE_GENERATING]);
        $this->database->updateDocument('certificates', 'certificate', new Document(['attempts' => 3]));
        $this->provider->renew = false;
        $this->provider->status = $status;

        $this->runWorker();

        $this->assertSame([['example.com', 'api']], $this->provider->issued);
        $this->assertSame(RULE_STATUS_CERTIFICATE_GENERATING, $this->rule()->getAttribute('status'));
        // The attempt is reserved before issuance and only reset once the provider confirms.
        $this->assertSame(4, $this->certificate()->getAttribute('attempts'));
    }

    public static function missing(): \Iterator
    {
        yield 'unknown' => [Status::UNKNOWN];
        yield 'failed' => [Status::FAILED];
    }

    public function testDelayedFailuresExhaustOneAttemptPerIssuance(): void
    {
        /**
         * Test for FAILURE
         */
        for ($attempt = 1; $attempt <= APP_LIMIT_CERTIFICATE_ATTEMPTS; $attempt++) {
            $this->runWorker();
            $this->assertSame($attempt, $this->certificate()->getAttribute('attempts'));
            $this->assertNull($this->certificate()->getAttribute('updated'));
            $this->assertSame(RULE_STATUS_CERTIFICATE_GENERATING, $this->rule()->getAttribute('status'));
            // The delayed provider resolves as failed after the generation job.
            $this->setRule(['status' => RULE_STATUS_CERTIFICATE_GENERATION_FAILED]);
        }
        $this->runWorker();
        $this->assertCount(APP_LIMIT_CERTIFICATE_ATTEMPTS, $this->provider->issued);
        $this->assertSame(RULE_STATUS_CERTIFICATE_GENERATION_FAILED, $this->rule()->getAttribute('status'));
    }

    public function testSynchronousFailureCountsOnceAndReleasesLease(): void
    {
        /**
         * Test for FAILURE
         */
        $this->provider->onIssue = static fn () => throw new \RuntimeException('Provider unavailable');
        try {
            $this->runWorker();
            $this->fail('Expected provider error');
        } catch (\RuntimeException $error) {
            $this->assertSame('Provider unavailable', $error->getMessage());
        }
        $this->assertSame(1, $this->certificate()->getAttribute('attempts'));
        $this->assertNull($this->certificate()->getAttribute('updated'));
        $this->assertSame(RULE_STATUS_CERTIFICATE_GENERATION_FAILED, $this->rule()->getAttribute('status'));
        $this->assertStringContainsString('Provider unavailable', (string) $this->rule()->getAttribute('logs'));
    }

    public function testConcurrentDuplicateDoesNotStartAnotherIssuance(): void
    {
        /**
         * Test for FAILURE
         */
        $this->provider->onIssue = function (): void {
            $this->provider->onIssue = null;
            $this->runWorker();
        };
        $this->runWorker();
        $this->assertCount(1, $this->provider->issued);
        $this->assertSame(1, $this->certificate()->getAttribute('attempts'));
    }

    #[DataProvider('lookupFailures')]
    public function testLookupFailuresExhaustRetries(string $lookup): void
    {
        /**
         * Test for FAILURE
         */
        $calls = 0;
        $this->provider->renew = false;
        $this->provider->{$lookup} = static function () use (&$calls): void {
            $calls++;
            throw new \RuntimeException('Provider lookup failed');
        };
        for ($attempt = 1; $attempt <= APP_LIMIT_CERTIFICATE_ATTEMPTS; $attempt++) {
            try {
                $this->runWorker();
                $this->fail('Expected provider lookup failure');
            } catch (\RuntimeException $error) {
                $this->assertSame('Provider lookup failed', $error->getMessage());
            }
            $this->assertSame($attempt, $this->certificate()->getAttribute('attempts'));
            $this->assertNull($this->certificate()->getAttribute('updated'));
        }

        $this->runWorker();
        $this->assertSame(APP_LIMIT_CERTIFICATE_ATTEMPTS, $calls);
        $this->assertCount(APP_LIMIT_CERTIFICATE_ATTEMPTS, $this->publisher->getEvents('mails'));
        $this->assertSame([], $this->provider->issued);
        $this->assertSame(RULE_STATUS_CERTIFICATE_GENERATION_FAILED, $this->rule()->getAttribute('status'));
    }

    public static function lookupFailures(): \Iterator
    {
        yield 'renewal lookup' => ['onRenew'];
        yield 'status lookup' => ['onStatus'];
    }

    #[DataProvider('recoveredCertificates')]
    public function testFinalAttemptReconcilesIssuedCertificate(bool $instant, string $status, bool $skipRenewCheck, ?string $renewDate): void
    {
        /**
         * Test for SUCCESS
         */
        $this->provider->instant = $instant;
        $this->provider->renew = false;
        $this->provider->status = Status::ISSUED;
        $this->setRule(['status' => $status]);
        $this->database->updateDocument('certificates', 'certificate', new Document([
            'attempts' => APP_LIMIT_CERTIFICATE_ATTEMPTS,
            'updated' => '2020-01-01T00:00:00.000+00:00',
            'renewDate' => $renewDate,
        ]));

        $this->runWorker(skipRenewCheck: $skipRenewCheck);

        $this->assertSame(RULE_STATUS_VERIFIED, $this->rule()->getAttribute('status'));
        $this->assertSame(0, $this->certificate()->getAttribute('attempts'));
        $this->assertNull($this->certificate()->getAttribute('updated'));
        $this->assertSame([], $this->provider->issued);
        if ($instant) {
            $this->assertNotEmpty($this->certificate()->getAttribute('renewDate'));
            $this->assertGreaterThan(new \DateTime(), new \DateTime($this->certificate()->getAttribute('renewDate')));
        }
    }

    public static function recoveredCertificates(): \Iterator
    {
        yield 'instant issuance' => [true, RULE_STATUS_CERTIFICATE_GENERATING, false, null];
        yield 'instant renewal' => [true, RULE_STATUS_VERIFIED, false, '2020-01-01T00:00:00.000+00:00'];
        yield 'delayed issuance' => [false, RULE_STATUS_CERTIFICATE_GENERATING, false, null];
        yield 'forced message' => [true, RULE_STATUS_CERTIFICATE_GENERATING, true, null];
    }

    public function testRecoveryPreservesFutureRenewalDate(): void
    {
        /**
         * Test for SUCCESS
         */
        $this->provider->instant = true;
        $this->provider->renew = false;
        $this->database->updateDocument('certificates', 'certificate', new Document(['renewDate' => '2099-01-01T00:00:00.000+00:00']));

        $this->runWorker();

        $this->assertSame('2099-01-01T00:00:00.000+00:00', $this->certificate()->getAttribute('renewDate'));
        $this->assertSame([], $this->provider->issued);
    }

    public function testFinalAttemptLookupFailureStopsRecovery(): void
    {
        /**
         * Test for FAILURE
         */
        $this->provider->onRenew = static fn () => throw new \RuntimeException('Provider unavailable');
        $this->setRule(['status' => RULE_STATUS_CERTIFICATE_GENERATING]);
        $this->database->updateDocument('certificates', 'certificate', new Document([
            'attempts' => APP_LIMIT_CERTIFICATE_ATTEMPTS,
            'updated' => '2020-01-01T00:00:00.000+00:00',
        ]));

        try {
            $this->runWorker();
            $this->fail('Expected provider lookup failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('Provider unavailable', $error->getMessage());
        }
        $this->runWorker();

        $this->assertSame(APP_LIMIT_CERTIFICATE_ATTEMPTS, $this->certificate()->getAttribute('attempts'));
        $this->assertSame(RULE_STATUS_CERTIFICATE_GENERATION_FAILED, $this->rule()->getAttribute('status'));
        $this->assertNull($this->certificate()->getAttribute('updated'));
        $this->assertSame([], $this->provider->issued);
        $this->assertCount(1, $this->publisher->getEvents('mails'));
    }

    #[DataProvider('exhaustedCertificates')]
    public function testFinalAttemptDoesNotIssueAgain(bool $renew, string $status, bool $skipRenewCheck): void
    {
        /**
         * Test for FAILURE
         */
        $this->provider->renew = $renew;
        $this->provider->status = $status;
        $this->setRule(['status' => RULE_STATUS_CERTIFICATE_GENERATING]);
        $this->database->updateDocument('certificates', 'certificate', new Document([
            'attempts' => APP_LIMIT_CERTIFICATE_ATTEMPTS,
            'updated' => '2020-01-01T00:00:00.000+00:00',
        ]));

        $this->runWorker(skipRenewCheck: $skipRenewCheck);
        $this->runWorker();

        $this->assertSame(RULE_STATUS_CERTIFICATE_GENERATION_FAILED, $this->rule()->getAttribute('status'));
        $this->assertSame(APP_LIMIT_CERTIFICATE_ATTEMPTS, $this->certificate()->getAttribute('attempts'));
        $this->assertNull($this->certificate()->getAttribute('updated'));
        $this->assertSame([], $this->provider->issued);
        $this->assertNull($this->publisher->getEvents('mails'));
    }

    public static function exhaustedCertificates(): \Iterator
    {
        yield 'renewal needed' => [true, Status::UNKNOWN, false];
        yield 'missing subscription' => [false, Status::UNKNOWN, false];
        yield 'forced message' => [true, Status::UNKNOWN, true];
    }

    public function testFinalAttemptPreservesPendingIssuance(): void
    {
        /**
         * Test for SUCCESS
         */
        $this->provider->renew = false;
        $this->setRule(['status' => RULE_STATUS_CERTIFICATE_GENERATING]);
        $this->database->updateDocument('certificates', 'certificate', new Document([
            'attempts' => APP_LIMIT_CERTIFICATE_ATTEMPTS,
            'updated' => '2020-01-01T00:00:00.000+00:00',
        ]));

        $this->runWorker();

        $this->assertSame(RULE_STATUS_CERTIFICATE_GENERATING, $this->rule()->getAttribute('status'));
        $this->assertSame(APP_LIMIT_CERTIFICATE_ATTEMPTS, $this->certificate()->getAttribute('attempts'));
        $this->assertNull($this->certificate()->getAttribute('updated'));
        $this->assertSame([], $this->provider->issued);
    }

    public function testPendingDuplicatePreservesAttemptsWithoutCallingIssuance(): void
    {
        /**
         * Test for SUCCESS
         */
        $this->runWorker();
        $this->provider->renew = false;
        $this->provider->status = Status::PENDING;
        $this->runWorker();
        $this->assertCount(1, $this->provider->issued);
        $this->assertSame(1, $this->certificate()->getAttribute('attempts'));
        $this->assertSame(RULE_STATUS_CERTIFICATE_GENERATING, $this->rule()->getAttribute('status'));
    }

    public function testInstantSuccessResetsAttemptsAndEmitsUpdatedRule(): void
    {
        /**
         * Test for SUCCESS
         */
        $this->provider->instant = true;
        $this->database->updateDocument('certificates', 'certificate', new Document(['attempts' => 3]));
        $this->runWorker();
        $this->assertSame(RULE_STATUS_VERIFIED, $this->rule()->getAttribute('status'));
        $this->assertSame(0, $this->certificate()->getAttribute('attempts'));
        $this->assertSame(RULE_STATUS_VERIFIED, $this->events->getPayload()['status']);
        $this->assertCount(1, $this->publisher->getEvents('functions'));
    }

    public function testStaleWorkerCannotOverwriteAReplacementLease(): void
    {
        /**
         * Test for FAILURE
         */
        $this->provider->onIssue = function (): void {
            $this->database->updateDocument('certificates', 'certificate', new Document(['updated' => '2099-01-01T00:00:00.000+00:00', 'attempts' => 4, 'logs' => 'new worker']));
            $this->setRule(['logs' => 'new worker']);
        };
        $this->runWorker();
        $this->assertSame(4, $this->certificate()->getAttribute('attempts'));
        $this->assertSame('new worker', $this->rule()->getAttribute('logs'));
        $this->assertSame(RULE_STATUS_CERTIFICATE_GENERATING, $this->rule()->getAttribute('status'));
        $this->assertNull($this->publisher->getEvents('functions'));
    }

    public function testDeletedRuleAndCertificateAreNotRecreatedOnCompletion(): void
    {
        /**
         * Test for FAILURE
         */
        $this->provider->onIssue = function (): void {
            $this->database->deleteDocument('rules', md5('example.com'));
            $this->database->deleteDocument('certificates', 'certificate');
        };
        $this->runWorker();
        $this->assertTrue($this->rule()->isEmpty());
        $this->assertTrue($this->certificate()->isEmpty());
        $this->assertNull($this->publisher->getEvents('functions'));
    }

    public function testLeaseReplacedDuringProviderLookupPreventsIssuance(): void
    {
        /**
         * Test for FAILURE
         */
        $this->provider->onRenew = function (): void {
            $this->database->updateDocument('certificates', 'certificate', new Document(['updated' => '2099-01-01T00:00:00.000+00:00', 'attempts' => 4]));
        };
        $this->runWorker();
        $this->assertSame([], $this->provider->issued);
        $this->assertSame(4, $this->certificate()->getAttribute('attempts'));
        $this->assertNull($this->publisher->getEvents('functions'));
    }

    public function testRecreatedRuleDuringLookupPreventsIssuance(): void
    {
        /**
         * Test for FAILURE
         */
        $this->provider->onRenew = function (): void {
            $replacement = $this->rule()->getArrayCopy();
            unset($replacement['$sequence']);
            $this->database->deleteDocument('rules', md5('example.com'));
            $this->database->createDocument('rules', new Document($replacement));
        };
        $this->runWorker();
        $this->assertSame([], $this->provider->issued);
        $this->assertNull($this->publisher->getEvents('functions'));
    }

    public function testStaleFailureDoesNotSendAnAdministratorEmail(): void
    {
        /**
         * Test for FAILURE
         */
        $this->provider->onIssue = function (): void {
            $this->database->deleteDocument('rules', md5('example.com'));
            throw new \RuntimeException('Deleted during issuance');
        };
        try {
            $this->runWorker();
            $this->fail('Expected provider error');
        } catch (\RuntimeException $error) {
            $this->assertSame('Deleted during issuance', $error->getMessage());
        }
        $this->assertNull($this->publisher->getEvents('mails'));
        $this->assertNull($this->publisher->getEvents('functions'));
    }

    #[DataProvider('rejections')]
    public function testIneligibleMessagesDoNotCallProvider(string $status, string $project): void
    {
        /**
         * Test for FAILURE
         */
        $this->setRule(['status' => $status]);
        $this->runWorker($project);
        $this->assertSame([], $this->provider->issued);
        $this->assertSame($status, $this->rule()->getAttribute('status'));
        $this->assertSame(0, $this->certificate()->getAttribute('attempts'));
        $this->assertNull($this->certificate()->getAttribute('updated'));
    }

    public static function rejections(): \Iterator
    {
        yield 'DNS not verified' => [RULE_STATUS_CREATED, 'project'];
        yield 'different project' => [RULE_STATUS_CERTIFICATE_GENERATION_FAILED, 'other-project'];
    }

    public function testExpiredLeaseCanRecoverAndUsesPersistedDomainType(): void
    {
        /**
         * Test for SUCCESS
         */
        $this->database->updateDocument('certificates', 'certificate', new Document(['updated' => '2020-01-01T00:00:00.000+00:00', 'attempts' => 2]));
        $this->setRule(['status' => RULE_STATUS_CERTIFICATE_GENERATING, 'deploymentResourceType' => 'site']);
        $this->runWorker();
        $this->assertSame([['example.com', 'site']], $this->provider->issued);
        $this->assertSame(3, $this->certificate()->getAttribute('attempts'));
    }

    #[DataProvider('consoleSequences')]
    public function testConsoleDomainsAcceptCurrentAndLegacyProjectSequence(?string $sequence): void
    {
        /**
         * Test for SUCCESS
         */
        $this->setRule(['projectId' => 'console', 'projectInternalId' => $sequence]);
        $this->runWorker('console', 'console');
        $this->assertCount(1, $this->provider->issued);
        $this->assertSame(1, $this->certificate()->getAttribute('attempts'));
        $this->assertNull($this->certificate()->getAttribute('updated'));
    }

    public static function consoleSequences(): \Iterator
    {
        yield ['console'];
        yield [null];
        yield ['0'];
    }

    private function runWorker(string $project = 'project', ?string $sequence = null, bool $skipRenewCheck = false): void
    {
        $webhooks = $this->createStub(Webhook::class);
        $webhooks->method('from')->willReturnSelf();
        $realtime = $this->createStub(Realtime::class);
        $realtime->method('setSubscribers')->willReturnSelf();
        $realtime->method('from')->willReturnSelf();
        $message = new CertificateMessage(
            project: new Document(['$id' => $project, '$sequence' => $sequence ?? $this->project->getSequence()]),
            domain: new Document(['domain' => 'example.com', 'domainType' => 'api']),
            validationDomain: 'example.com',
            skipRenewCheck: $skipRenewCheck,
        );
        (new Certificates())->action(
            (new Message())->setPayload($message->toArray()),
            $this->database,
            new MailPublisher($this->publisher, new Queue('mails')),
            $this->events,
            $webhooks,
            new FunctionPublisher($this->publisher, new Queue('functions')),
            $realtime,
            new CertificatePublisher($this->publisher, new Queue('certificates')),
            $this->provider,
            [],
            new Authorization(),
            $this->bus,
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

    private function setRule(array $attributes): void
    {
        $this->database->updateDocument('rules', md5('example.com'), new Document($attributes));
    }
}
