<?php

declare(strict_types=1);

namespace Utopia\Storage\Tests\Device;

use PHPUnit\Framework\TestCase;
use Utopia\Psr7\Stream;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Device\Telemetry;
use Utopia\Storage\Exception\NotModifiedException;
use Utopia\Telemetry\Adapter\Test as TestTelemetry;

final class TelemetryTest extends TestCase
{
    public function testStorageOperationTelemetryIsCreatedOnFirstRecord(): void
    {
        $telemetry = new TestTelemetry();
        $underlying = new Local(__DIR__ . '/../resources/disk-a');
        $device = new Telemetry($telemetry, $underlying);
        $path = $underlying->getPath('lorem.txt');

        $this->assertArrayNotHasKey('storage.operation', $telemetry->histograms);

        $device->exists($path);

        $this->assertArrayHasKey('storage.operation', $telemetry->histograms);
    }

    public function testDecoratedDeviceIsAccessible(): void
    {
        $underlying = new Local(__DIR__ . '/../resources/disk-a');
        $device = new Telemetry(new TestTelemetry(), $underlying);

        $this->assertSame($underlying, $device->getDevice());
    }

    public function testConditionalOperationsAreForwardedAndMeasured(): void
    {
        $telemetry = new TestTelemetry();
        $underlying = new Local(sys_get_temp_dir());
        $device = new Telemetry($telemetry, $underlying);
        $path = $underlying->getPath('telemetry-' . bin2hex(random_bytes(4)) . '.txt');

        $etag = $device->create($path, new Stream('one'), 'text/plain');
        $this->assertSame(md5('one'), $etag);
        $this->assertSame(md5('two'), $device->replace($path, new Stream('two'), $etag, 'text/plain'));
        $this->assertSame('two', (string) $device->read($path, 0, null, md5('two')));

        try {
            $device->read($path, modifiedSince: new \DateTimeImmutable('+1 hour'));
            $this->fail('The time must reach the decorated device');
        } catch (NotModifiedException) {
            $this->assertSame('two', (string) $device->read($path, modifiedSince: new \DateTimeImmutable('@1')));
        }
        $this->assertSame(3, $device->getFileInfo($path)->size);

        $underlying->delete($path);

        $this->assertArrayHasKey('storage.operation', $telemetry->histograms);
    }

    public function testListingUploadsOnADeviceWithoutThemIsRefused(): void
    {
        $device = new Telemetry(new TestTelemetry(), new Local(sys_get_temp_dir()));

        $this->expectException(\BadMethodCallException::class);
        $device->listUploads();
    }
}
