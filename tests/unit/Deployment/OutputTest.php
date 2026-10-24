<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use Appwrite\Deployment\Output;
use PHPUnit\Framework\TestCase;
use Utopia\Storage\Device\Local;
use Utopia\Storage\DeviceType;
use Utopia\Storage\Exception\StorageException;

final class OutputTest extends TestCase
{
    private string $root;
    private Local $device;

    protected function setUp(): void
    {
        $root = \sys_get_temp_dir() . '/output-' . \bin2hex(\random_bytes(8));
        \mkdir($root);
        $root = \realpath($root);
        self::assertNotFalse($root);
        $this->root = $root;
        $this->device = new Local($root);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->root . '/*') ?: [] as $path) {
            \unlink($path);
        }
        \rmdir($this->root);
    }

    public function testFindsOutputWithoutACallbackMarker(): void
    {
        $path = $this->device->getPath('output.tar.gz');
        \file_put_contents($path, 'build output');

        self::assertTrue(Output::wait($this->device, $path, 0));
    }

    public function testMissingOutputReturnsAfterTheGracePeriod(): void
    {
        $path = $this->device->getPath('missing.tar.gz');

        self::assertFalse(Output::wait($this->device, $path, 0.01));
        self::assertFileDoesNotExist($path);
    }

    public function testAcceptsOutputThatAppearsDuringTheGracePeriod(): void
    {
        $path = $this->device->getPath('output.tar.gz');
        $process = \proc_open(
            [PHP_BINARY, '-r', 'usleep(50_000); file_put_contents($argv[1], "build output");', $path],
            [],
            $pipes,
        );
        self::assertIsResource($process);

        try {
            self::assertTrue(Output::wait($this->device, $path, 5));
            self::assertSame('build output', \file_get_contents($path));
        } finally {
            \proc_close($process);
        }
    }

    public function testDoesNotAcceptASymlinkAsBuildOutput(): void
    {
        $target = $this->device->getPath('target.tar.gz');
        $link = $this->device->getPath('output.tar.gz');
        \file_put_contents($target, 'build output');
        \symlink($target, $link);

        self::assertFalse(Output::wait($this->device, $link, 0));
        self::assertSame('build output', \file_get_contents($target));
    }

    public function testStorageFailureIsNotReportedAsMissingOutput(): void
    {
        $this->expectException(StorageException::class);

        Output::wait(new UnavailableStorage(), 'output.tar.gz', 0);
    }
}

final class UnavailableStorage extends Local
{
    public function getType(): DeviceType
    {
        return DeviceType::S3;
    }

    public function exists(string $path): bool
    {
        throw new StorageException('Storage is temporarily unavailable');
    }
}
