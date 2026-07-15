<?php

declare(strict_types=1);

namespace Tests\Unit\Functions;

use Appwrite\Platform\Modules\Functions\Workers\Jobs;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Utopia\Database\Document;
use Utopia\Storage\Device;

final class JobsTest extends TestCase
{
    public function testFindBuildPathKeepsExistingDeploymentPath(): void
    {
        $path = '/storage/builds/app-project/deployment/code.tar.gz';
        $device = $this->createMock(Device::class);
        $device->expects($this->once())->method('exists')->with($path)->willReturn(true);
        $device->expects($this->never())->method('getFiles');

        $this->assertSame($path, $this->findBuildPath($device, new Document([
            '$id' => 'deployment',
            'buildPath' => $path,
        ])));
    }

    public function testFindBuildPathDiscoversOpaqueArtifactPath(): void
    {
        $path = '/storage/builds/app-project/deployment/code.sqfs';
        $device = $this->createMock(Device::class);
        $device->expects($this->never())->method('exists');
        $device
            ->expects($this->once())
            ->method('getFiles')
            ->with('/storage/builds/app-project/deployment')
            ->willReturn([$path]);

        $this->assertSame($path, $this->findBuildPath($device, new Document([
            '$id' => 'deployment',
            'buildPath' => '',
        ])));
    }

    public function testFindBuildPathRejectsAmbiguousOutput(): void
    {
        $device = $this->createMock(Device::class);
        $device
            ->expects($this->once())
            ->method('getFiles')
            ->willReturn(['/build/code.sqfs', '/build/code.next']);

        $this->assertSame('', $this->findBuildPath($device, new Document([
            '$id' => 'deployment',
            'buildPath' => '',
        ])));
    }

    private function findBuildPath(Device $device, Document $deployment): string
    {
        $method = new ReflectionMethod(Jobs::class, 'findBuildPath');

        return $method->invoke(new Jobs(), $device, 'project', $deployment);
    }
}
