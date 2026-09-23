<?php

declare(strict_types=1);

namespace Utopia\Tests\Adapter\SMS;

use Utopia\Messaging\Adapter\SMS as SMSAdapter;
use Utopia\Messaging\Adapter\SMS\GEOSMS;
use Utopia\Messaging\Adapter\SMS\GEOSMS\CallingCode;
use Utopia\Messaging\Messages\SMS;
use Utopia\Tests\Adapter\Base;

final class GEOSMSTest extends Base
{
    public function testSendSMSUsingDefaultAdapter(): void
    {
        $defaultAdapterMock = $this->createStub(SMSAdapter::class);
        $defaultAdapterMock->method('getName')->willReturn('default');
        $defaultAdapterMock->method('send')->willReturn(['results' => [['status' => 'success']]]);

        $adapter = new GEOSMS($defaultAdapterMock);

        $to = ['+11234567890'];
        $from = 'Sender';

        $message = new SMS(
            to: $to,
            content: 'Test Content',
            from: $from,
        );

        $result = $adapter->send($message);

        $this->assertCount(1, $result);
        $this->assertEquals('success', $result['default']['results'][0]['status']);
    }

    public function testSendSMSUsingLocalAdapter(): void
    {
        $defaultAdapterMock = $this->createStub(SMSAdapter::class);
        $localAdapterMock = $this->createStub(SMSAdapter::class);
        $localAdapterMock->method('getName')->willReturn('local');
        $localAdapterMock->method('send')->willReturn(['results' => [['status' => 'success']]]);

        $adapter = new GEOSMS($defaultAdapterMock);
        $adapter->setLocal(CallingCode::INDIA, $localAdapterMock);

        $to = ['+911234567890'];
        $from = 'Sender';

        $message = new SMS(
            to: $to,
            content: 'Test Content',
            from: $from,
        );

        $result = $adapter->send($message);

        $this->assertCount(1, $result);
        $this->assertEquals('success', $result['local']['results'][0]['status']);
    }

    public function testSendSMSUsingLocalAdapterAndDefault(): void
    {
        $defaultAdapterMock = $this->createStub(SMSAdapter::class);
        $defaultAdapterMock->method('getName')->willReturn('default');
        $defaultAdapterMock->method('send')->willReturn(['results' => [['status' => 'success']]]);
        $localAdapterMock = $this->createStub(SMSAdapter::class);
        $localAdapterMock->method('getName')->willReturn('local');
        $localAdapterMock->method('send')->willReturn(['results' => [['status' => 'success']]]);

        $adapter = new GEOSMS($defaultAdapterMock);
        $adapter->setLocal(CallingCode::INDIA, $localAdapterMock);

        $to = ['+911234567890', '+11234567890'];
        $from = 'Sender';

        $message = new SMS(
            to: $to,
            content: 'Test Content',
            from: $from,
        );

        $result = $adapter->send($message);

        $this->assertCount(2, $result);
        $this->assertEquals('success', $result['local']['results'][0]['status']);
        $this->assertEquals('success', $result['default']['results'][0]['status']);
    }

    public function testSendSMSUsingGroupedLocalAdapter(): void
    {
        $defaultAdapterMock = $this->createStub(SMSAdapter::class);
        $localAdapterMock = $this->createStub(SMSAdapter::class);
        $localAdapterMock->method('getName')->willReturn('local');
        $localAdapterMock->method('send')->willReturn(['results' => [['status' => 'success']]]);

        $adapter = new GEOSMS($defaultAdapterMock);
        $adapter->setLocal(CallingCode::INDIA, $localAdapterMock);
        $adapter->setLocal(CallingCode::NORTH_AMERICA, $localAdapterMock);

        $to = ['+911234567890', '+11234567890'];
        $from = 'Sender';

        $message = new SMS(
            to: $to,
            content: 'Test Content',
            from: $from,
        );

        $result = $adapter->send($message);

        $this->assertCount(1, $result);
        $this->assertEquals('success', $result['local']['results'][0]['status']);
    }

    public function testSendSMSHandlesMetadata(): void
    {
        $metadata = [
            'clientId' => 'client-123',
            'CRQID' => 'request_123',
            'UUID' => 'uuid.123',
        ];

        $defaultAdapterMock = $this->createMock(SMSAdapter::class);
        $defaultAdapterMock->method('getName')->willReturn('default');
        $defaultAdapterMock
            ->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (SMS $message) use ($metadata): array {
                $this->assertSame($metadata, $message->getMetadata());
                return ['results' => [['status' => 'success']]];
            });

        $adapter = new GEOSMS($defaultAdapterMock);

        $message = new SMS(
            to: ['+11234567890'],
            content: 'Test Content',
            metadata: $metadata,
        );

        $result = $adapter->send($message);

        $this->assertCount(1, $result);
        $this->assertEquals('success', $result['default']['results'][0]['status']);

        $defaultMetadata = [
            'clientId' => 'client-123',
            'CRQID' => 'request_123-2',
            'UUID' => 'uuid.123-2',
        ];

        $localMetadata = [
            'clientId' => 'client-123',
            'CRQID' => 'request_123-1',
            'UUID' => 'uuid.123-1',
        ];

        $defaultAdapterMock = $this->createMock(SMSAdapter::class);
        $defaultAdapterMock->method('getName')->willReturn('default');
        $defaultAdapterMock
            ->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (SMS $message) use ($defaultMetadata): array {
                $this->assertSame($defaultMetadata, $message->getMetadata());
                return ['results' => [['status' => 'success']]];
            });

        $localAdapterMock = $this->createMock(SMSAdapter::class);
        $localAdapterMock->method('getName')->willReturn('local');
        $localAdapterMock
            ->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (SMS $message) use ($localMetadata): array {
                $this->assertSame($localMetadata, $message->getMetadata());
                return ['results' => [['status' => 'success']]];
            });

        $adapter = new GEOSMS($defaultAdapterMock);
        $adapter->setLocal(CallingCode::INDIA, $localAdapterMock);

        $message = new SMS(
            to: ['+911234567890', '+11234567890'],
            content: 'Test Content',
            metadata: $metadata,
        );

        $result = $adapter->send($message);

        $this->assertCount(2, $result);
        $this->assertEquals('success', $result['local']['results'][0]['status']);
        $this->assertEquals('success', $result['default']['results'][0]['status']);

        $invalidMetadata = [
            'CRQID' => [],
        ];

        $message = new SMS(
            to: ['+911234567890', '+11234567890'],
            content: 'Test Content',
            metadata: $invalidMetadata,
        );

        try {
            $adapter->send($message);
            $this->fail('Expected invalid GEOSMS metadata to throw.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Msg91 CRQID metadata must be a string', $e->getMessage());
        }

        $message = new SMS(
            to: ['+911234567890', '+11234567890'],
            content: 'Test Content',
            metadata: [
                'CRQID' => '',
            ],
        );

        try {
            $adapter->send($message);
            $this->fail('Expected empty GEOSMS metadata to throw.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Msg91 CRQID metadata must be 80 characters or less', $e->getMessage());
        }
    }
}
