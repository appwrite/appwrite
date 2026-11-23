<?php

declare(strict_types=1);

namespace Utopia\Distribution\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Distribution\Exception\Invalid;
use Utopia\Distribution\Upload;
use Utopia\Storage\Device\Local;

final class UploadTest extends TestCase
{
    public function testValidUpload(): void
    {
        $device = new Local(sys_get_temp_dir() . '/distribution');

        $upload = new Upload($device, 'builds/app.aab', 'app.aab', 2048, 'io.example.app', '1.2.0', '7', Upload::PLATFORM_ANDROID);

        $this->assertSame($device, $upload->device);
        $this->assertSame('builds/app.aab', $upload->path);
        $this->assertSame('app.aab', $upload->name);
        $this->assertSame(2048, $upload->size);
        $this->assertSame('io.example.app', $upload->identifier);
        $this->assertSame('1.2.0', $upload->version);
        $this->assertSame('7', $upload->build);
        $this->assertSame(Upload::PLATFORM_ANDROID, $upload->platform);
    }

    public function testSingleByteIsAccepted(): void
    {
        $upload = new Upload(new Local(sys_get_temp_dir()), 'app.ipa', 'App.ipa', 1, 'io.example.app', '1.0.0', '1', Upload::PLATFORM_IOS);

        $this->assertSame(1, $upload->size);
    }

    /**
     * @return iterable<string, array{string, string, int, string, string, string, string}>
     */
    public static function invalid(): iterable
    {
        yield 'zero size' => ['app.ipa', 'App.ipa', 0, 'io.example.app', '1.0.0', '1', Upload::PLATFORM_IOS];
        yield 'negative size' => ['app.ipa', 'App.ipa', -1, 'io.example.app', '1.0.0', '1', Upload::PLATFORM_IOS];
        yield 'unknown platform' => ['app.ipa', 'App.ipa', 1, 'io.example.app', '1.0.0', '1', 'windows'];
        yield 'empty platform' => ['app.ipa', 'App.ipa', 1, 'io.example.app', '1.0.0', '1', ''];
        yield 'empty path' => ['', 'App.ipa', 1, 'io.example.app', '1.0.0', '1', Upload::PLATFORM_IOS];
        yield 'empty name' => ['app.ipa', '', 1, 'io.example.app', '1.0.0', '1', Upload::PLATFORM_IOS];
        yield 'empty identifier' => ['app.ipa', 'App.ipa', 1, '', '1.0.0', '1', Upload::PLATFORM_IOS];
        yield 'empty version' => ['app.ipa', 'App.ipa', 1, 'io.example.app', '', '1', Upload::PLATFORM_IOS];
        yield 'empty build' => ['app.ipa', 'App.ipa', 1, 'io.example.app', '1.0.0', '', Upload::PLATFORM_IOS];
    }

    #[DataProvider('invalid')]
    public function testInvalidUploadThrows(string $path, string $name, int $size, string $identifier, string $version, string $build, string $platform): void
    {
        $this->expectException(Invalid::class);

        new Upload(new Local(sys_get_temp_dir()), $path, $name, $size, $identifier, $version, $build, $platform);
    }
}
