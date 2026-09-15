<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Event\Event;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Realtime;
use Appwrite\Event\Webhook;
use Appwrite\Platform\Workers\Certificates;
use PHPUnit\Framework\TestCase;
use Utopia\Bus\Bus;
use Utopia\Database\Database;
use Utopia\Database\Document;

require_once __DIR__ . '/../../../../app/init.php';

final class CertificatesTest extends TestCase
{
    /**
     * Legacy rules exist without a projectId. The rule update must still be
     * written and broadcast, and the per-project events skipped, instead of
     * failing on a project lookup with no id (CLOUD-3R0T).
     */
    public function testRuleWithoutProjectIsUpdatedWithoutProjectEvents(): void
    {
        $rule = new Document([
            '$id' => 'rule1',
            'domain' => 'legacy.example.com',
            'status' => RULE_STATUS_CERTIFICATE_GENERATING,
            'certificateId' => '',
            'logs' => '',
            'projectId' => null,
        ]);

        $dbForPlatform = $this->createMock(Database::class);
        $dbForPlatform->expects($this->once())
            ->method('updateDocument')
            ->with('rules', 'rule1')
            ->willReturn($rule);
        $dbForPlatform->expects($this->never())->method('getDocument');

        $bus = $this->createMock(Bus::class);
        $bus->expects($this->once())->method('dispatch');

        $queueForEvents = $this->createMock(Event::class);
        $queueForEvents->expects($this->never())->method('setProject');

        $queueForWebhooks = $this->createMock(Webhook::class);
        $queueForWebhooks->expects($this->never())->method('trigger');

        $publisherForFunctions = $this->createMock(FunctionPublisher::class);
        $publisherForFunctions->expects($this->never())->method('enqueue');

        $queueForRealtime = $this->createMock(Realtime::class);
        $queueForRealtime->expects($this->never())->method('trigger');

        $worker = new class () extends Certificates {
            public function update(Document $rule, Database $dbForPlatform, Event $queueForEvents, Webhook $queueForWebhooks, FunctionPublisher $publisherForFunctions, Realtime $queueForRealtime, Bus $bus): void
            {
                $this->updateRuleAndSendEvents($rule, $dbForPlatform, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $queueForRealtime, $bus);
            }
        };

        $worker->update($rule, $dbForPlatform, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $queueForRealtime, $bus);
    }
}
