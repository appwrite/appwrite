<?php

declare(strict_types=1);

namespace Tests\E2E\General\Certificates;

use Appwrite\Event\Publisher\Certificate;
use Appwrite\Platform\Tasks\SSL;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Event\MockPublisher;
use Utopia\Bus\Bus;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Queue\Queue;

final class SSLTest extends TestCase
{
    private const DOMAIN = 'api.example.com';

    private Database $database;
    private MockPublisher $publisher;
    private string|false $format;

    protected function setUp(): void
    {
        $this->format = getenv('_APP_RULES_FORMAT');
        putenv('_APP_RULES_FORMAT=md5');
        $this->database = new Database();
        $this->publisher = new MockPublisher();
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->delete();
        }
        putenv($this->format === false ? '_APP_RULES_FORMAT' : '_APP_RULES_FORMAT=' . $this->format);
    }

    public function testCustomerRetryResetsBudgetAndQueuesPersistedProjectAndProvider(): void
    {
        /**
         * Test for SUCCESS
         */
        $this->seed();
        $this->runTask();

        $this->assertSame(0, $this->database->getDocument('certificates', 'certificate')->getAttribute('attempts'));
        $this->assertNull($this->database->getDocument('certificates', 'certificate')->getAttribute('updated'));
        $this->assertSame(RULE_STATUS_CERTIFICATE_GENERATING, $this->database->getDocument('rules', md5(self::DOMAIN))->getAttribute('status'));
        $events = $this->publisher->getEvents('certificates');
        $this->assertCount(1, $events);
        $this->assertSame('customer', $events[0]['project']['$id']);
        $this->assertSame('7', $events[0]['project']['$sequence']);
        $this->assertSame(['domain' => self::DOMAIN, 'domainType' => 'site'], $events[0]['domain']);
        $this->assertTrue($events[0]['skipRenewCheck']);
    }

    public function testExpiredGenerationLeaseCanBeRetried(): void
    {
        /**
         * Test for SUCCESS
         */
        $this->seed('2020-01-01T00:00:00.000+00:00');
        $this->runTask();

        $certificate = $this->database->getDocument('certificates', 'certificate');
        $this->assertSame(0, $certificate->getAttribute('attempts'));
        $this->assertNull($certificate->getAttribute('updated'));
        $this->assertCount(1, $this->publisher->getEvents('certificates'));
    }

    public function testActiveGenerationLeasePreservesBudgetAndDoesNotQueue(): void
    {
        /**
         * Test for FAILURE
         */
        $this->seed(DateTime::now());
        $this->runTask();

        $certificate = $this->database->getDocument('certificates', 'certificate');
        $this->assertSame(APP_LIMIT_CERTIFICATE_ATTEMPTS, $certificate->getAttribute('attempts'));
        $this->assertNotNull($certificate->getAttribute('updated'));
        $this->assertSame(RULE_STATUS_CERTIFICATE_GENERATION_FAILED, $this->database->getDocument('rules', md5(self::DOMAIN))->getAttribute('status'));
        $this->assertNull($this->publisher->getEvents('certificates'));
    }

    public function testNewServerDomainKeepsConsoleProjectIdentity(): void
    {
        /**
         * Test for SUCCESS
         */
        $this->runTask();

        $rule = $this->database->getDocument('rules', md5(self::DOMAIN));
        $this->assertSame('console', $rule->getAttribute('projectId'));
        $this->assertSame('console', $rule->getAttribute('projectInternalId'));
        $events = $this->publisher->getEvents('certificates');
        $this->assertCount(1, $events);
        $this->assertSame('console', $events[0]['project']['$id']);
        $this->assertSame('console', $events[0]['project']['$sequence']);
        $this->assertSame('', $events[0]['domain']['domainType']);
    }

    public function testConcurrentDeletionDoesNotQueueOrRecreateRule(): void
    {
        /**
         * Test for FAILURE
         */
        $this->seed();
        $this->onRuleRead(function (): void {
            $this->database->deleteDocument('rules', md5(self::DOMAIN));
        });
        $this->runTask();

        $this->assertTrue($this->database->getDocument('rules', md5(self::DOMAIN))->isEmpty());
        $this->assertSame(APP_LIMIT_CERTIFICATE_ATTEMPTS, $this->database->getDocument('certificates', 'certificate')->getAttribute('attempts'));
        $this->assertNull($this->publisher->getEvents('certificates'));
    }

    public function testConcurrentRecreationPreservesReplacementRule(): void
    {
        /**
         * Test for FAILURE
         */
        $this->seed();
        $this->onRuleRead(function (): void {
            $this->database->deleteDocument('rules', md5(self::DOMAIN));
            $this->database->createDocument('rules', new Document([
                '$id' => md5(self::DOMAIN), 'domain' => self::DOMAIN, 'region' => 'default',
                'projectId' => 'replacement', 'projectInternalId' => '8',
                'status' => RULE_STATUS_CREATED,
            ]));
        });
        $this->runTask();

        $this->assertSame(RULE_STATUS_CREATED, $this->database->getDocument('rules', md5(self::DOMAIN))->getAttribute('status'));
        $this->assertSame(APP_LIMIT_CERTIFICATE_ATTEMPTS, $this->database->getDocument('certificates', 'certificate')->getAttribute('attempts'));
        $this->assertNull($this->publisher->getEvents('certificates'));
    }

    private function seed(?string $updated = null): void
    {
        $this->database->createDocument('certificates', new Document([
            '$id' => 'certificate', 'domain' => self::DOMAIN,
            'attempts' => APP_LIMIT_CERTIFICATE_ATTEMPTS, 'updated' => $updated,
        ]));
        $this->database->createDocument('rules', new Document([
            '$id' => md5(self::DOMAIN), 'domain' => self::DOMAIN, 'region' => 'default',
            'projectId' => 'customer', 'projectInternalId' => '7',
            'certificateId' => 'certificate', 'type' => 'deployment',
            'deploymentResourceType' => 'site', 'status' => RULE_STATUS_CERTIFICATE_GENERATION_FAILED,
        ]));
    }

    private function onRuleRead(\Closure $callback): void
    {
        $this->database->on(Database::EVENT_DOCUMENT_READ, 'concurrent', function (string $event, Document $document) use ($callback): void {
            if ($document->getCollection() !== 'rules') {
                return;
            }
            $this->database->on(Database::EVENT_DOCUMENT_READ, 'concurrent', null);
            $callback();
        });
    }

    private function runTask(): void
    {
        (new SSL())->action(
            self::DOMAIN,
            'true',
            new Document(['$id' => 'console', '$sequence' => 'console', 'region' => 'default']),
            $this->database,
            new Certificate($this->publisher, new Queue('certificates')),
            (new Bus())->setResolver(static fn () => null),
        );
    }
}
