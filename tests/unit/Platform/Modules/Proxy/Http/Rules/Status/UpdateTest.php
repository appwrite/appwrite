<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Proxy\Http\Rules\Status;

use Appwrite\Event\Event;
use Appwrite\Event\Publisher\Certificate;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Proxy\Http\Rules\Status\Update;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Event\MockPublisher;
use Tests\Unit\Platform\CertificateDatabase;
use Utopia\Bus\Bus;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Queue\Queue;

final class UpdateTest extends TestCase
{
    private CertificateDatabase $database;
    private MockPublisher $publisher;

    protected function setUp(): void
    {
        $this->database = new CertificateDatabase();
        $this->publisher = new MockPublisher();
        $this->database->createDocument('certificates', new Document(['$id' => 'certificate', 'attempts' => APP_LIMIT_CERTIFICATE_ATTEMPTS, 'logs' => 'old error']));
        $this->database->createDocument('rules', new Document([
            '$id' => 'rule', 'projectInternalId' => '7', 'domain' => 'example.com',
            'certificateId' => 'certificate', 'type' => 'deployment', 'deploymentResourceType' => 'site',
            'status' => RULE_STATUS_CERTIFICATE_GENERATION_FAILED,
        ]));
    }

    public function testVerifiedDnsStartsFreshBudgetAndQueuesPersistedProvider(): void
    {
        $this->runAction();
        $this->assertSame(0, $this->database->getDocument('certificates', 'certificate')->getAttribute('attempts'));
        $this->assertSame(RULE_STATUS_CERTIFICATE_GENERATING, $this->database->getDocument('rules', 'rule')->getAttribute('status'));
        $events = $this->publisher->getEvents('certificates');
        $this->assertCount(1, $events);
        $this->assertSame('site', $events[0]['domain']['domainType']);
        $this->runAction();
        $this->assertCount(1, $this->publisher->getEvents('certificates'));
    }

    public function testInvalidDnsPreservesBudgetAndDoesNotQueue(): void
    {
        try {
            $this->runAction(static fn () => throw new Exception(Exception::RULE_VERIFICATION_FAILED));
            $this->fail('DNS rejection must reach caller');
        } catch (Exception $error) {
            $this->assertSame(Exception::RULE_VERIFICATION_FAILED, $error->getType());
        }
        $this->assertSame(APP_LIMIT_CERTIFICATE_ATTEMPTS, $this->database->getDocument('certificates', 'certificate')->getAttribute('attempts'));
        $this->assertNull($this->publisher->getEvents('certificates'));
    }

    public function testForeignProjectCannotResetBudget(): void
    {
        $this->database->writes = [];
        try {
            $this->runAction(project: 8);
            $this->fail('Foreign project must be rejected');
        } catch (Exception $error) {
            $this->assertSame(Exception::RULE_NOT_FOUND, $error->getType());
        }
        $this->assertSame([], $this->database->writes);
        $this->assertNull($this->publisher->getEvents('certificates'));
    }

    public function testConcurrentRetryCannotResetAnIssuingWorkersBudget(): void
    {
        $this->runAction(function (): void {
            $this->database->updateDocument('rules', 'rule', new Document(['status' => RULE_STATUS_CERTIFICATE_GENERATING]));
            $this->database->updateDocument('certificates', 'certificate', new Document(['attempts' => 1]));
        });
        $this->assertSame(1, $this->database->getDocument('certificates', 'certificate')->getAttribute('attempts'));
        $this->assertNull($this->publisher->getEvents('certificates'));
    }

    private function runAction(?\Closure $verify = null, int $project = 7): void
    {
        $action = new class ($verify) extends Update {
            public function __construct(private ?\Closure $verify)
            {
            }
            protected function verifyRule(Document $rule): void
            {
                ($this->verify ?? static fn () => null)();
            }
        };
        $action->action(
            'rule',
            $this->createStub(Response::class),
            new Certificate($this->publisher, new Queue('certificates')),
            new Event($this->publisher),
            new Document(['$id' => 'project', '$sequence' => $project]),
            $this->database,
            new Authorization(),
            (new Bus())->setResolver(static fn () => null),
        );
    }
}
