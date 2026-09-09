<?php

declare(strict_types=1);

namespace Tests\E2E\General\Certificates;

use Appwrite\Certificates\Certificates;
use Appwrite\Event\Publisher\Certificate;
use Appwrite\Event\Publisher\Delete;
use Appwrite\Platform\Tasks\Maintenance;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Event\MockPublisher;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Queue\Queue;

final class MaintenanceTest extends TestCase
{
    private Database $database;
    private MockPublisher $publisher;
    private string|false $region;
    private string|false $format;

    protected function setUp(): void
    {
        $this->region = getenv('_APP_REGION');
        $this->format = getenv('_APP_RULES_FORMAT');
        putenv('_APP_REGION=default');
        putenv('_APP_RULES_FORMAT=md5');
        $this->database = new Database();
        $this->publisher = new MockPublisher();
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->delete();
        }
        putenv($this->region === false ? '_APP_REGION' : '_APP_REGION=' . $this->region);
        putenv($this->format === false ? '_APP_RULES_FORMAT' : '_APP_RULES_FORMAT=' . $this->format);
    }

    public function testSkippedCertificatesDoNotConsumeTheRenewalBudget(): void
    {
        /**
         * Test for SUCCESS
         */
        $due = '2020-01-01T00:00:00.000+00:00';
        for ($i = 0; $i < 201; $i++) {
            $this->seed('elsewhere' . $i, $due, region: 'other');
        }
        for ($i = 0; $i < 201; $i++) {
            $this->seed('renewal' . $i, $due);
        }

        $this->runTask();

        $domains = array_column(array_column($this->publisher->getEvents('certificates') ?? [], 'domain'), 'domain');
        $this->assertSame(array_map(static fn (int $i) => 'renewal' . $i . '.example.com', range(0, 199)), $domains);
    }

    public function testExpiredLeaseIsQueuedForReconciliation(): void
    {
        /**
         * Test for SUCCESS
         */
        $due = '2020-01-01T00:00:00.000+00:00';
        $expired = '2020-01-01T00:00:00.000+00:00';
        $active = DateTime::now();
        $future = DateTime::formatTz(DateTime::format(new \DateTime('+1 day')));
        // Exhausted certificates are only revisited when a worker died holding the lease.
        $this->seed('expired', $due, attempts: APP_LIMIT_CERTIFICATE_ATTEMPTS, updated: $expired);
        $this->seed('failed', $due, attempts: APP_LIMIT_CERTIFICATE_ATTEMPTS, status: RULE_STATUS_CERTIFICATE_GENERATION_FAILED, updated: $expired);
        $this->seed('active', $due, attempts: APP_LIMIT_CERTIFICATE_ATTEMPTS, updated: $active);
        $this->seed('complete', $due, attempts: APP_LIMIT_CERTIFICATE_ATTEMPTS);
        $this->seed('future', $future, attempts: APP_LIMIT_CERTIFICATE_ATTEMPTS, updated: $expired);
        $this->seed('renewal', $due);

        $this->runTask();

        $domains = array_column(array_column($this->publisher->getEvents('certificates') ?? [], 'domain'), 'domain');
        $this->assertSame(['expired.example.com', 'renewal.example.com'], $domains);
        $this->assertSame(APP_LIMIT_CERTIFICATE_ATTEMPTS, $this->database->getDocument('certificates', 'expired')->getAttribute('attempts'));
        $this->assertSame(RULE_STATUS_VERIFIED, $this->database->getDocument('rules', md5('expired.example.com'))->getAttribute('status'));
    }

    public function testExhaustedFailuresDoNotConsumeTheRenewalBudget(): void
    {
        /**
         * Test for SUCCESS
         */
        $due = '2020-01-01T00:00:00.000+00:00';
        for ($i = 0; $i < 201; $i++) {
            $this->seed('failed' . $i, $due, attempts: APP_LIMIT_CERTIFICATE_ATTEMPTS, status: RULE_STATUS_CERTIFICATE_GENERATION_FAILED, updated: $due);
        }
        for ($i = 0; $i < 201; $i++) {
            $this->seed('renewal' . $i, $due);
        }

        $this->runTask();

        $domains = array_column(array_column($this->publisher->getEvents('certificates') ?? [], 'domain'), 'domain');
        $this->assertSame(array_map(static fn (int $i) => 'renewal' . $i . '.example.com', range(0, 199)), $domains);
    }

    public function testCertificateTheRuleNoLongerUsesIsNotRenewed(): void
    {
        /**
         * Test for FAILURE
         */
        $this->seed('current', DateTime::formatTz(DateTime::format(new \DateTime('+1 day'))));
        $this->database->createDocument('certificates', new Document([
            '$id' => 'previous',
            'domain' => 'current.example.com',
            'attempts' => 0,
            'renewDate' => '2020-01-01T00:00:00.000+00:00',
        ]));

        $this->runTask();

        $this->assertNull($this->publisher->getEvents('certificates'));
    }

    private function seed(string $id, string $renewDate, string $region = 'default', int $attempts = 0, string $status = RULE_STATUS_VERIFIED, ?string $updated = null): void
    {
        $domain = $id . '.example.com';
        $this->database->createDocument('certificates', new Document([
            '$id' => $id,
            'domain' => $domain,
            'attempts' => $attempts,
            'updated' => $updated,
            'renewDate' => $renewDate,
        ]));
        $this->database->createDocument('rules', new Document([
            '$id' => md5($domain),
            'domain' => $domain,
            'type' => 'api',
            'region' => $region,
            'projectId' => 'project',
            'projectInternalId' => '7',
            'certificateId' => $id,
            'status' => $status,
        ]));
    }

    private function runTask(): void
    {
        (new Maintenance())->action(
            'trigger',
            $this->database,
            new Document(['$id' => 'console', '$sequence' => 'console']),
            new Certificate($this->publisher, new Queue('certificates')),
            new Certificates(),
            new Delete($this->publisher, new Queue('deletes')),
        );
    }
}
