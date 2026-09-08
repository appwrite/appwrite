<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Tasks;

use Appwrite\Certificates\Certificates;
use Appwrite\Event\Publisher\Certificate;
use Appwrite\Event\Publisher\Delete;
use Appwrite\Platform\Tasks\Maintenance;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Event\MockPublisher;
use Tests\Unit\Platform\CertificateDatabase;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Queue\Queue;

final class MaintenanceTest extends TestCase
{
    private CertificateDatabase $database;
    private MockPublisher $publisher;
    private string|false $region;
    private string|false $format;

    protected function setUp(): void
    {
        $this->region = getenv('_APP_REGION');
        $this->format = getenv('_APP_RULES_FORMAT');
        putenv('_APP_REGION=default');
        putenv('_APP_RULES_FORMAT=md5');
        $this->database = new CertificateDatabase();
        $this->publisher = new MockPublisher();
    }

    protected function tearDown(): void
    {
        putenv($this->region === false ? '_APP_REGION' : '_APP_REGION=' . $this->region);
        putenv($this->format === false ? '_APP_RULES_FORMAT' : '_APP_RULES_FORMAT=' . $this->format);
    }

    public function testExpiredFinalRenewalIsQueuedForReconciliation(): void
    {
        $expired = '2020-01-01T00:00:00.000+00:00';
        $now = DateTime::now();
        $future = DateTime::formatTz(DateTime::format(new \DateTime('+1 day')));
        $cases = [
            ['expired', APP_LIMIT_CERTIFICATE_ATTEMPTS, RULE_STATUS_VERIFIED, $expired, $expired],
            ['failed', APP_LIMIT_CERTIFICATE_ATTEMPTS, RULE_STATUS_CERTIFICATE_GENERATION_FAILED, $expired, $expired],
            ['active', APP_LIMIT_CERTIFICATE_ATTEMPTS, RULE_STATUS_VERIFIED, $now, $expired],
            ['complete', APP_LIMIT_CERTIFICATE_ATTEMPTS, RULE_STATUS_VERIFIED, null, $expired],
            ['future', APP_LIMIT_CERTIFICATE_ATTEMPTS, RULE_STATUS_VERIFIED, $expired, $future],
            ['renewal', 0, RULE_STATUS_VERIFIED, null, $expired],
        ];
        foreach ($cases as $case) {
            $this->seed(...$case);
        }

        $this->runTask();

        $domains = array_column(array_column($this->publisher->getEvents('certificates') ?? [], 'domain'), 'domain');
        $this->assertSame(['expired.example.com', 'renewal.example.com'], $domains);
        $this->assertSame(APP_LIMIT_CERTIFICATE_ATTEMPTS, $this->database->getDocument('certificates', 'expired')->getAttribute('attempts'));
        $this->assertSame(RULE_STATUS_VERIFIED, $this->database->getDocument('rules', md5('expired.example.com'))->getAttribute('status'));
    }

    public function testSkippedCertificatesDoNotStarveRenewalsOrConsumeLimit(): void
    {
        $expired = '2020-01-01T00:00:00.000+00:00';
        for ($i = 0; $i < 201; $i++) {
            $this->seed('failed' . $i, APP_LIMIT_CERTIFICATE_ATTEMPTS, RULE_STATUS_CERTIFICATE_GENERATION_FAILED, $expired, $expired);
        }
        for ($i = 0; $i < 201; $i++) {
            $this->seed('renewal' . $i, 0, RULE_STATUS_VERIFIED, null, $expired);
        }

        $this->runTask();

        $domains = array_column(array_column($this->publisher->getEvents('certificates') ?? [], 'domain'), 'domain');
        $this->assertSame(array_map(static fn (int $i) => 'renewal' . $i . '.example.com', range(0, 199)), $domains);
    }

    public function testStaleCertificateDoesNotScheduleReplacement(): void
    {
        $future = DateTime::formatTz(DateTime::format(new \DateTime('+1 day')));
        $this->seed('replacement', 0, RULE_STATUS_VERIFIED, null, $future);
        $this->database->createDocument('certificates', new Document([
            '$id' => 'stale',
            'domain' => 'replacement.example.com',
            'attempts' => 0,
            'updated' => null,
            'renewDate' => '2020-01-01T00:00:00.000+00:00',
        ]));

        $this->runTask();

        $this->assertNull($this->publisher->getEvents('certificates'));
    }

    private function seed(string $id, int $attempts, string $status, ?string $updated, string $renewDate): void
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
            'region' => 'default',
            'projectId' => 'project',
            'projectInternalId' => 7,
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
