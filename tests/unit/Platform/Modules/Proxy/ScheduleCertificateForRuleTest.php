<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Proxy;

use Appwrite\Event\Event;
use Appwrite\Event\Publisher\Certificate as CertificatePublisher;
use Appwrite\Platform\Modules\Proxy\Action;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Event\MockPublisher;
use Utopia\Database\Document;
use Utopia\Queue\Queue;

require_once __DIR__ . '/../../../../../app/init.php';
require_once __DIR__ . '/../../../Event/MockPublisher.php';

/**
 * Concrete stand-in so we can exercise the protected schedule helper.
 */
final class ProxyActionHarness extends Action
{
    public function schedule(
        CertificatePublisher $publisher,
        Document $project,
        Document $rule,
    ): void {
        $this->scheduleCertificateForRule($publisher, $project, $rule);
    }
}

final class ScheduleCertificateForRuleTest extends TestCase
{
    private MockPublisher $publisher;
    private CertificatePublisher $certificatePublisher;
    private ProxyActionHarness $action;
    private Document $project;

    protected function setUp(): void
    {
        $this->publisher = new MockPublisher();
        $this->certificatePublisher = new CertificatePublisher(
            $this->publisher,
            new Queue(Event::CERTIFICATES_QUEUE_NAME)
        );
        $this->action = new ProxyActionHarness();
        $this->project = new Document([
            '$id' => 'proj',
            '$sequence' => 7,
            'database' => 'db',
        ]);
    }

    public function testAppwriteOwnedVerifiedPublicDomainEnqueuesCertificate(): void
    {
        $rule = new Document([
            'domain' => 'fn123.functions.example.com',
            'status' => RULE_STATUS_VERIFIED,
            'owner' => 'Appwrite',
            'type' => 'deployment',
            'deploymentResourceType' => 'function',
        ]);

        $this->action->schedule($this->certificatePublisher, $this->project, $rule);

        $events = $this->publisher->getEvents(Event::CERTIFICATES_QUEUE_NAME);
        $this->assertCount(1, $events);
        $this->assertSame('fn123.functions.example.com', $events[0]['domain']['domain']);
        $this->assertSame('function', $events[0]['domain']['domainType']);
        $this->assertTrue($events[0]['skipRenewCheck']);
    }

    public function testAppwriteOwnedVerifiedLocalhostDoesNotEnqueue(): void
    {
        $rule = new Document([
            'domain' => 'fn123.functions.localhost',
            'status' => RULE_STATUS_VERIFIED,
            'owner' => 'Appwrite',
            'type' => 'deployment',
            'deploymentResourceType' => 'function',
        ]);

        $this->action->schedule($this->certificatePublisher, $this->project, $rule);

        $this->assertNull($this->publisher->getEvents(Event::CERTIFICATES_QUEUE_NAME));
    }

    public function testCertificateGeneratingStatusEnqueuesWithoutPublicCheck(): void
    {
        $rule = new Document([
            'domain' => 'custom.customer.com',
            'status' => RULE_STATUS_CERTIFICATE_GENERATING,
            'owner' => '',
            'type' => 'deployment',
            'deploymentResourceType' => 'function',
        ]);

        $this->action->schedule($this->certificatePublisher, $this->project, $rule);

        $events = $this->publisher->getEvents(Event::CERTIFICATES_QUEUE_NAME);
        $this->assertCount(1, $events);
        $this->assertFalse($events[0]['skipRenewCheck']);
        $this->assertSame('custom.customer.com', $events[0]['domain']['domain']);
    }

    public function testCertificateGeneratingAppwriteOwnedLocalhostDoesNotEnqueue(): void
    {
        // Defensive: Appwrite-owned + generating must still honor public hostname guard.
        $rule = new Document([
            'domain' => 'fn123.functions.localhost',
            'status' => RULE_STATUS_CERTIFICATE_GENERATING,
            'owner' => 'Appwrite',
            'type' => 'deployment',
            'deploymentResourceType' => 'function',
        ]);

        $this->action->schedule($this->certificatePublisher, $this->project, $rule);

        $this->assertNull($this->publisher->getEvents(Event::CERTIFICATES_QUEUE_NAME));
    }

    public function testUnverifiedNonAppwriteRuleDoesNotEnqueue(): void
    {
        $rule = new Document([
            'domain' => 'pending.customer.com',
            'status' => RULE_STATUS_CREATED,
            'owner' => '',
            'type' => 'deployment',
            'deploymentResourceType' => 'function',
        ]);

        $this->action->schedule($this->certificatePublisher, $this->project, $rule);

        $this->assertNull($this->publisher->getEvents(Event::CERTIFICATES_QUEUE_NAME));
    }

    public function testAppwriteOwnedSiteDomainEnqueues(): void
    {
        $rule = new Document([
            'domain' => 'abc.sites.mycompany.io',
            'status' => RULE_STATUS_VERIFIED,
            'owner' => 'Appwrite',
            'type' => 'deployment',
            'deploymentResourceType' => 'site',
        ]);

        $this->action->schedule($this->certificatePublisher, $this->project, $rule);

        $events = $this->publisher->getEvents(Event::CERTIFICATES_QUEUE_NAME);
        $this->assertCount(1, $events);
        $this->assertSame('site', $events[0]['domain']['domainType']);
        $this->assertTrue($events[0]['skipRenewCheck']);
    }
}
