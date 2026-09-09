<?php

declare(strict_types=1);

namespace Tests\E2E\General\Certificates;

use Appwrite\Event\Publisher\Certificate;
use Appwrite\Platform\Tasks\Interval;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Event\MockPublisher;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Queue\Queue;

final class IntervalTest extends TestCase
{
    private Database $database;
    private MockPublisher $publisher;
    private string|false $region;
    private string|false $edition;
    private string|false $autoCertificates;

    protected function setUp(): void
    {
        $this->region = getenv('_APP_REGION');
        $this->edition = getenv('_APP_EDITION');
        $this->autoCertificates = getenv('_APP_ROUTER_AUTO_CERTIFICATES');
        putenv('_APP_REGION=default');
        putenv('_APP_EDITION=self-hosted');
        putenv('_APP_ROUTER_AUTO_CERTIFICATES=enabled');
        $this->database = new Database();
        $this->publisher = new MockPublisher();
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->delete();
        }
        putenv($this->region === false ? '_APP_REGION' : '_APP_REGION=' . $this->region);
        putenv($this->edition === false ? '_APP_EDITION' : '_APP_EDITION=' . $this->edition);
        putenv($this->autoCertificates === false ? '_APP_ROUTER_AUTO_CERTIFICATES' : '_APP_ROUTER_AUTO_CERTIFICATES=' . $this->autoCertificates);
    }

    public function testOlderFailuresAreNotStarvedByExhaustedFirstPage(): void
    {
        /**
         * Test for SUCCESS
         */
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
        /**
         * Test for SUCCESS
         */
        $this->seed('other-region', 0, ['region' => 'elsewhere']);
        $this->seed('recent', 0, ['$updatedAt' => DateTime::now()]);
        $this->seed('active', 0, [], ['updated' => DateTime::now()]);
        $this->seed('pending', 2, ['status' => RULE_STATUS_CERTIFICATE_GENERATING]);
        $this->seed('crashed', 2, ['status' => RULE_STATUS_CERTIFICATE_GENERATING], ['updated' => '2020-01-01T00:00:00.000+00:00']);
        $this->seed('missing', 0, ['certificateId' => 'missing-certificate']);
        $this->runTask();
        $domains = array_column(array_column($this->publisher->getEvents('certificates') ?? [], 'domain'), 'domain');
        $this->assertSame(['crashed.example.com', 'missing.example.com'], $domains);
    }

    public function testRecentExpiredWorkDoesNotWaitUntilTheNextDay(): void
    {
        /**
         * Test for SUCCESS
         */
        $expired = DateTime::formatTz(DateTime::format(new \DateTime('-30 minutes')));
        $this->seed('failed', 1, ['$updatedAt' => $expired]);
        $this->seed('expired', 2, ['$updatedAt' => $expired, 'status' => RULE_STATUS_CERTIFICATE_GENERATING], ['updated' => $expired]);
        $this->runTask();
        $domains = array_column(array_column($this->publisher->getEvents('certificates') ?? [], 'domain'), 'domain');
        $this->assertSame(['failed.example.com', 'expired.example.com'], $domains);
    }

    #[DataProvider('issuancePolicies')]
    public function testAutomaticIssuancePolicyRespected(string $edition, string $autoCertificates, array $expected): void
    {
        /**
         * Test for SUCCESS
         */
        putenv('_APP_EDITION=' . $edition);
        putenv('_APP_ROUTER_AUTO_CERTIFICATES=' . $autoCertificates);
        $this->seed('owned', 1, [
            'owner' => 'Appwrite',
            'type' => 'deployment',
            'deploymentResourceType' => 'site',
        ]);
        $this->seed('custom', 1, [
            'type' => 'deployment',
            'deploymentResourceType' => 'site',
        ]);

        $this->runTask();

        $domains = array_column(array_column($this->publisher->getEvents('certificates') ?? [], 'domain'), 'domain');
        $this->assertSame($expected, $domains);
    }

    public static function issuancePolicies(): \Iterator
    {
        yield 'enabled self-hosted' => ['self-hosted', 'enabled', ['owned.example.com', 'custom.example.com']];
        yield 'disabled self-hosted' => ['self-hosted', 'disabled', ['custom.example.com']];
        yield 'cloud' => ['cloud', 'enabled', ['custom.example.com']];
    }

    public function testExpiredFinalAttemptIsQueuedForReconciliation(): void
    {
        /**
         * Test for SUCCESS
         */
        $this->seed('failed', APP_LIMIT_CERTIFICATE_ATTEMPTS);
        $this->seed('active', APP_LIMIT_CERTIFICATE_ATTEMPTS, ['status' => RULE_STATUS_CERTIFICATE_GENERATING], ['updated' => DateTime::now()]);
        $this->seed('pending', APP_LIMIT_CERTIFICATE_ATTEMPTS, ['status' => RULE_STATUS_CERTIFICATE_GENERATING]);
        $this->seed('expired', APP_LIMIT_CERTIFICATE_ATTEMPTS, ['status' => RULE_STATUS_CERTIFICATE_GENERATING], ['updated' => '2020-01-01T00:00:00.000+00:00']);

        $this->runTask();

        $domains = array_column(array_column($this->publisher->getEvents('certificates') ?? [], 'domain'), 'domain');
        $this->assertSame(['expired.example.com'], $domains);
        $this->assertSame(APP_LIMIT_CERTIFICATE_ATTEMPTS, $this->database->getDocument('certificates', 'expired')->getAttribute('attempts'));
        $this->runTask();
        $this->assertCount(1, $this->publisher->getEvents('certificates'), 'Claimed work must not be queued again on the next tick');
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
            'projectId' => 'project', 'projectInternalId' => '7',
            'certificateId' => $id, 'status' => RULE_STATUS_CERTIFICATE_GENERATION_FAILED,
        ], $rule)));
    }

    private function runTask(): void
    {
        // Exercise the registered callback without starting Swoole's timer loop.
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
