<?php

declare(strict_types=1);

namespace Tests\Unit\Certificates;

use Appwrite\Certificates\Scheduler;
use Appwrite\Event\Event;
use Appwrite\Event\Publisher\Certificate as CertificatePublisher;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Event\MockPublisher;
use Utopia\Database\Document;
use Utopia\Queue\Queue;

require_once __DIR__ . '/../../../app/init.php';
require_once __DIR__ . '/../Event/MockPublisher.php';

final class SchedulerTest extends TestCase
{
    private MockPublisher $publisher;
    private CertificatePublisher $certificatePublisher;
    private Document $project;

    protected function setUp(): void
    {
        $this->publisher = new MockPublisher();
        $this->certificatePublisher = new CertificatePublisher(
            $this->publisher,
            new Queue(Event::CERTIFICATES_QUEUE_NAME)
        );
        $this->project = new Document([
            '$id' => 'project-id',
            '$sequence' => 1,
            'database' => 'db',
        ]);
    }

    public function testEnqueueGenerationForPublicFunctionDomain(): void
    {
        $domain = 'abc123.functions.example.com';

        $enqueued = Scheduler::enqueueGeneration(
            $this->certificatePublisher,
            $this->project,
            $domain,
            'function',
            skipRenewCheck: true,
            requirePublicHostname: true,
        );

        $this->assertTrue($enqueued);

        $events = $this->publisher->getEvents(Event::CERTIFICATES_QUEUE_NAME);
        $this->assertCount(1, $events);
        $this->assertSame($domain, $events[0]['domain']['domain']);
        $this->assertSame('function', $events[0]['domain']['domainType']);
        $this->assertTrue($events[0]['skipRenewCheck']);
        $this->assertSame(\Appwrite\Event\Certificate::ACTION_GENERATION, $events[0]['action']);
        $this->assertSame('project-id', $events[0]['project']['$id']);
    }

    public function testEnqueueGenerationSkipsLocalhostFunctionDomain(): void
    {
        $enqueued = Scheduler::enqueueGeneration(
            $this->certificatePublisher,
            $this->project,
            'abc123.functions.localhost',
            'function',
            skipRenewCheck: true,
            requirePublicHostname: true,
        );

        $this->assertFalse($enqueued);
        $this->assertNull($this->publisher->getEvents(Event::CERTIFICATES_QUEUE_NAME));
    }

    public function testEnqueueGenerationSkipsTestTld(): void
    {
        $enqueued = Scheduler::enqueueGeneration(
            $this->certificatePublisher,
            $this->project,
            'abc123.functions.example.test',
            'function',
            skipRenewCheck: true,
            requirePublicHostname: true,
        );

        $this->assertFalse($enqueued);
        $this->assertNull($this->publisher->getEvents(Event::CERTIFICATES_QUEUE_NAME));
    }

    public function testEnqueueGenerationSkipsEmptyDomain(): void
    {
        $enqueued = Scheduler::enqueueGeneration(
            $this->certificatePublisher,
            $this->project,
            '',
            'function',
            skipRenewCheck: true,
            requirePublicHostname: true,
        );

        $this->assertFalse($enqueued);
        $this->assertNull($this->publisher->getEvents(Event::CERTIFICATES_QUEUE_NAME));
    }

    public function testEnqueueGenerationWithoutPublicHostnameCheckAlwaysQueues(): void
    {
        // Custom domains that already passed DNS verification should always enqueue,
        // matching historical createFunctionRule behavior for status=verifying.
        $domain = 'custom.example.com';

        $enqueued = Scheduler::enqueueGeneration(
            $this->certificatePublisher,
            $this->project,
            $domain,
            'function',
            skipRenewCheck: false,
            requirePublicHostname: false,
        );

        $this->assertTrue($enqueued);

        $events = $this->publisher->getEvents(Event::CERTIFICATES_QUEUE_NAME);
        $this->assertCount(1, $events);
        $this->assertFalse($events[0]['skipRenewCheck']);
        $this->assertSame($domain, $events[0]['domain']['domain']);
    }

    public function testEnqueueGenerationForPublicSiteDomain(): void
    {
        $domain = 'preview.sites.myapp.io';

        $enqueued = Scheduler::enqueueGeneration(
            $this->certificatePublisher,
            $this->project,
            $domain,
            'site',
            skipRenewCheck: true,
            requirePublicHostname: true,
        );

        $this->assertTrue($enqueued);

        $events = $this->publisher->getEvents(Event::CERTIFICATES_QUEUE_NAME);
        $this->assertCount(1, $events);
        $this->assertSame('site', $events[0]['domain']['domainType']);
    }
}
