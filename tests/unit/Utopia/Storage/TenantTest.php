<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Storage;

use Appwrite\Utopia\Storage\Tenant;
use PHPUnit\Framework\TestCase;
use Utopia\Storage\Device\Local;

final class TenantTest extends TestCase
{
    private Local $device;

    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->device = new Local(\sys_get_temp_dir() . '/tenant-test-' . \getmypid());
        $this->tenant = new Tenant($this->device, 'app-project-a');
    }

    protected function tearDown(): void
    {
        if ($this->device->exists($this->tenant->getRoot())) {
            $this->device->delete($this->tenant->getRoot(), true);
        }
    }

    public function testPathsAreScopedToTheTenant(): void
    {
        $path = $this->tenant->getPath('bucket/file.txt');

        $this->assertSame($this->device->getRoot() . '/app-project-a/bucket/file.txt', $path);
        $this->assertSame($this->device->getRoot() . '/app-project-a', $this->tenant->getRoot());
    }

    public function testOperationsWorkInsideTheScope(): void
    {
        $path = $this->tenant->getPath('file.txt');

        $this->assertTrue($this->tenant->write($path, 'Hello World', 'text/plain'));
        $this->assertTrue($this->tenant->exists($path));
        $this->assertSame('Hello World', $this->tenant->read($path));
        $this->assertSame(11, $this->tenant->getFileSize($path));
        $this->assertTrue($this->tenant->delete($path));
    }

    public function testTraversalCannotEscapeTheTenant(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tenant->read($this->tenant->getRoot() . '/../app-project-b/secret.txt');
    }

    public function testTraversalInFilenamesCannotEscapeTheTenant(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tenant->getPath('../app-project-b/secret.txt');
    }

    public function testForeignTenantPathsAreRejected(): void
    {
        $foreign = new Tenant($this->device, 'app-project-b');

        $this->expectException(\InvalidArgumentException::class);
        $this->tenant->read($foreign->getPath('file.txt'));
    }

    public function testDeletingTheTenantRootIsAllowed(): void
    {
        $path = $this->tenant->getPath('file.txt');
        $this->tenant->write($path, 'Hello World', 'text/plain');

        $this->assertTrue($this->tenant->delete($this->tenant->getRoot(), true));
        $this->assertFalse($this->tenant->exists($path));
    }
}
