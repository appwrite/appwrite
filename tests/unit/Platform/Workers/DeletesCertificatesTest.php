<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Event\Publisher\Usage;
use Appwrite\Execution\Store;
use Appwrite\Platform\Workers\Deletes;
use Executor\Executor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Event\MockPublisher;
use Tests\Unit\Platform\CertificateDatabase;
use Tests\Unit\Platform\CertificateProvider;
use Utopia\Bus\Bus;
use Utopia\Database\Document;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;
use Utopia\Storage\Device;

final class DeletesCertificatesTest extends TestCase
{
    #[DataProvider('types')]
    public function testRuleDeletionCleansUpTheIssuingProvider(array $attributes, string $expected): void
    {
        $database = new CertificateDatabase();
        $provider = new CertificateProvider();
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
        $database = new CertificateDatabase();
        $provider = new CertificateProvider();
        $database->createDocument('certificates', new Document(['$id' => 'certificate']));
        $database->createDocument('rules', new Document(['$id' => 'replacement', 'domain' => 'example.com', 'certificateId' => 'certificate']));
        $this->runWorker($database, $provider, ['type' => 'api']);
        $this->assertSame([], $provider->deleted);
        $this->assertFalse($database->getDocument('certificates', 'certificate')->isEmpty());
    }

    private function runWorker(CertificateDatabase $database, CertificateProvider $provider, array $attributes): void
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
            (new Bus())->setResolver(static fn () => null),
            $this->createStub(Store::class),
        );
    }
}
