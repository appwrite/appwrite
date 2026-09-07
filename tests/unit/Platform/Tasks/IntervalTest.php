<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Tasks;

use Appwrite\Event\Publisher\Certificate;
use Appwrite\Platform\Tasks\Interval;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Event\MockPublisher;
use Tests\Unit\Platform\CertificateDatabase;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Queue\Queue;

final class IntervalTest extends TestCase
{
    private CertificateDatabase $database;
    private MockPublisher $publisher;
    private string|false $region;

    protected function setUp(): void
    {
        $this->region = getenv('_APP_REGION');
        putenv('_APP_REGION=default');
        $this->database = new CertificateDatabase();
        $this->publisher = new MockPublisher();
    }

    protected function tearDown(): void
    {
        putenv($this->region === false ? '_APP_REGION' : '_APP_REGION=' . $this->region);
    }

    public function testOlderFailuresAreNotStarvedByExhaustedFirstPage(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->seed('capped' . $i, APP_LIMIT_CERTIFICATE_ATTEMPTS);
        }
        $this->seed('eligible', 2);
        $this->runTask();
        $events = $this->publisher->getEvents('certificates');
        $this->assertCount(1, $events);
        $this->assertSame('eligible.example.com', $events[0]['domain']['domain']);
        $this->assertSame(2, $this->database->getDocument('certificates', 'eligible')->getAttribute('attempts'));
        $this->assertSame(RULE_STATUS_CERTIFICATE_GENERATION_FAILED, $this->database->getDocument('rules', 'eligible')->getAttribute('status'));
        $this->runTask();
        $this->assertCount(1, $this->publisher->getEvents('certificates'), 'Another tick cannot enqueue an already claimed rule');
    }

    public function testOnlyEligibleRegionAndExpiredWorkAreQueued(): void
    {
        $this->seed('other-region', 0, ['region' => 'elsewhere']);
        $this->seed('recent', 0, ['$updatedAt' => DateTime::now()]);
        $this->seed('active', 0, [], ['updated' => DateTime::now()]);
        $this->seed('pending', 2, ['status' => RULE_STATUS_CERTIFICATE_GENERATING]);
        $this->seed('crashed', 2, ['status' => RULE_STATUS_CERTIFICATE_GENERATING], ['updated' => '2020-01-01T00:00:00.000+00:00']);
        $this->seed('missing', 0, ['certificateId' => 'missing-certificate']);
        $this->runTask();
        $this->assertSame(['crashed.example.com', 'missing.example.com'], $this->queuedDomains());
    }

    public function testRecentExpiredWorkDoesNotWaitUntilTheNextDay(): void
    {
        $expired = DateTime::formatTz(DateTime::format(new \DateTime('-30 minutes')));
        $this->seed('failed', 1, ['$updatedAt' => $expired]);
        $this->seed('expired', 2, ['$updatedAt' => $expired, 'status' => RULE_STATUS_CERTIFICATE_GENERATING], ['updated' => $expired]);
        $this->runTask();
        $this->assertSame(['failed.example.com', 'expired.example.com'], $this->queuedDomains());
    }

    /** @return array<int, string> */
    private function queuedDomains(): array
    {
        return array_column(array_column($this->publisher->getEvents('certificates') ?? [], 'domain'), 'domain');
    }

    private function seed(string $id, int $attempts, array $rule = [], array $certificate = []): void
    {
        $this->database->createDocument('certificates', new Document(array_merge([
            '$id' => $id, 'domain' => $id . '.example.com', 'attempts' => $attempts, 'updated' => null,
        ], $certificate)));
        $this->database->createDocument('rules', new Document(array_merge([
            '$id' => $id,
            '$createdAt' => '2020-01-01T00:00:00.000+00:00',
            '$updatedAt' => '2020-01-01T00:00:00.000+00:00',
            'domain' => $id . '.example.com', 'type' => 'api', 'region' => 'default',
            'projectId' => 'project', 'projectInternalId' => 7,
            'certificateId' => $id, 'status' => RULE_STATUS_CERTIFICATE_GENERATION_FAILED,
        ], $rule)));
    }

    private function runTask(): void
    {
        // Exercise the registered callback without starting Swoole's long-lived
        // timer/event loop. No private method is exposed by this test seam.
        $interval = new class () extends Interval {
            public function getTasks(): array
            {
                return parent::getTasks();
            }
        };
        foreach ($interval->getTasks() as $task) {
            if ($task['name'] === 'certificateGeneration') {
                $task['callback']($this->database, static fn () => null, new Certificate($this->publisher, new Queue('certificates')));
                return;
            }
        }
        $this->fail('Certificate generation task is not registered');
    }
}
