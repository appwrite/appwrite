<?php

declare(strict_types=1);

namespace Tests\Unit\Network;

use Appwrite\Network\Platform;
use PHPUnit\Framework\TestCase;

final class PlatformTest extends TestCase
{
    public function testWebHostnamesAreTrusted(): void
    {
        $hostnames = Platform::getHostnames([
            ['type' => Platform::TYPE_WEB, 'hostname' => 'App.Example.com'],
            ['type' => 'flutter-web', 'hostname' => 'flutter.example.com'],
            ['type' => Platform::TYPE_WEB, 'hostname' => ''],
        ]);

        $this->assertSame(['app.example.com', 'flutter.example.com'], \array_values($hostnames));
    }

    public function testNativeAppIdsAreNotHostnames(): void
    {
        // Read as a DNS name, an app ID is a public domain anyone may register.
        $hostnames = Platform::getHostnames([
            ['type' => Platform::TYPE_ANDROID, 'key' => 'com.acme.app'],
            ['type' => 'flutter-ios', 'key' => 'com.acme.ios'],
            ['type' => Platform::TYPE_WINDOWS, 'key' => 'acme.windows'],
            ['type' => Platform::TYPE_LINUX, 'key' => 'acme.linux'],
        ]);

        $this->assertSame([], $hostnames);
    }

    public function testNativeAppsKeepTheirSchemes(): void
    {
        $schemes = Platform::getSchemes([
            ['type' => Platform::TYPE_ANDROID, 'key' => 'com.acme.app'],
        ]);

        $this->assertSame([Platform::SCHEME_ANDROID], \array_values($schemes));
    }
}
