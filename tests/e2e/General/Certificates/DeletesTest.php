<?php

declare(strict_types=1);

namespace Tests\E2E\General\Certificates;

use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Event\Publisher\Usage;
use Appwrite\Execution\Store;
use Appwrite\Platform\Workers\Deletes;
use Executor\Executor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Event\MockPublisher;
use Utopia\Bus\Bus;
use Utopia\Database\Document;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;
use Utopia\Storage\Device;

final class DeletesTest extends TestCase
{
    private Database $database;

    protected function setUp(): void
    {
        $this->database = new Database();
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->delete();
        }
    }

    #[DataProvider('types')]
    public function testRuleDeletionCleansUpTheIssuingProvider(array $attributes, string $expected): void
    {
        /**
         * Test for SUCCESS
         */
        $database = $this->database;
        $provider = new Provider();
        $database->createDocument('certificates', new Document(['$id' => 'certificate']));
        $this->runWorker($database, $provider, $attributes);
        $this->assertSame([['example.com', $expected]], $provider->deleted);
        $this->assertTrue($database->getDocument('certificates', 'certificate')->isEmpty());
    }

    public static function types(): \Iterator
    {
        yield 'API' => [['type' => 'api'], 'api'];
        yield 'function' => [['type' => 'deployment', 'deploymentResourceType' => 'function'], 'function'];
        yield 'site' => [['type' => 'deployment', 'deploymentResourceType' => 'site'], 'site'];
        yield 'redirect' => [['type' => 'redirect', 'deploymentResourceType' => 'site'], 'site'];
    }

    public function testStaleDeletionPreservesRecreatedDomainsCertificate(): void
    {
        /**
         * Test for FAILURE
         */
        $database = $this->database;
        $provider = new Provider();
        $database->createDocument('certificates', new Document(['$id' => 'certificate']));
        $database->createDocument('rules', new Document(['$id' => 'replacement', 'domain' => 'example.com', 'certificateId' => 'certificate']));
        $this->runWorker($database, $provider, ['type' => 'api']);
        $this->assertSame([], $provider->deleted);
        $this->assertFalse($database->getDocument('certificates', 'certificate')->isEmpty());
    }

    #[DataProvider('replacements')]
    public function testStaleDeletionRemovesUnreferencedCertificate(string $certificateId): void
    {
        /**
         * Test for FAILURE
         */
        $database = $this->database;
        $provider = new Provider();
        $database->createDocument('certificates', new Document(['$id' => 'certificate', 'domain' => 'example.com']));
        if ($certificateId !== '') {
            $database->createDocument('certificates', new Document(['$id' => $certificateId, 'domain' => 'example.com']));
        }
        $database->createDocument('rules', new Document([
            '$id' => 'replacement', 'domain' => 'example.com', 'certificateId' => $certificateId,
        ]));
        $bus = $this->createMock(Bus::class);
        $bus->expects($this->never())->method('dispatch');

        $this->runWorker($database, $provider, ['type' => 'api'], $bus);

        $this->assertSame([], $provider->deleted);
        $this->assertFalse($database->getDocument('rules', 'replacement')->isEmpty());
        if ($certificateId !== '') {
            $this->assertFalse($database->getDocument('certificates', $certificateId)->isEmpty());
        }
        $this->assertTrue($database->getDocument('certificates', 'certificate')->isEmpty());
    }

    public static function replacements(): \Iterator
    {
        yield 'new certificate' => ['new-certificate'];
        yield 'no certificate yet' => [''];
    }

    public function testStaleDeletionPreservesCertificateReferencedByAnotherRule(): void
    {
        /**
         * Test for FAILURE
         */
        $database = $this->database;
        $provider = new Provider();
        $database->createDocument('certificates', new Document(['$id' => 'certificate']));
        $database->createDocument('rules', new Document(['$id' => 'replacement', 'domain' => 'example.com', 'certificateId' => '']));
        $database->createDocument('rules', new Document(['$id' => 'other', 'domain' => 'other.example.com', 'certificateId' => 'certificate']));

        $this->runWorker($database, $provider, ['type' => 'api']);

        $this->assertSame([], $provider->deleted);
        $this->assertFalse($database->getDocument('certificates', 'certificate')->isEmpty());
    }

    private function runWorker(Database $database, Provider $provider, array $attributes, ?Bus $bus = null): void
    {
        $publisher = new MockPublisher();
        $device = $this->createStub(Device::class);
        $message = new DeleteMessage(type: DELETE_TYPE_DOCUMENT, document: new Document(array_merge([
            '$id' => 'old-rule', '$collection' => DELETE_TYPE_RULES, 'domain' => 'example.com', 'certificateId' => 'certificate',
        ], $attributes)));
        (new Deletes())->action(
            (new Message())->setPayload($message->toArray()),
            new Document(['$id' => 'project']),
            $database,
            static fn () => $database,
            static fn () => $database,
            static fn () => $database,
            $device,
            $device,
            $device,
            $device,
            $device,
            $provider,
            $this->createStub(Executor::class),
            '14 days',
            100,
            new DeletePublisher($publisher, new Queue('deletes')),
            new Usage($publisher, new Queue('usage')),
            $bus ?? (new Bus())->setResolver(static fn () => null),
            $this->createStub(Store::class),
        );
    }
}
