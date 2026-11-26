<?php

declare(strict_types=1);

namespace Tests\Unit\Network;

use Appwrite\Network\Platform;
use PHPUnit\Framework\TestCase;

final class PlatformTest extends TestCase
{
    /**
     * @var list<array{type: string, hostname?: string, key?: string}>
     */
    private const PLATFORMS = [
        ['type' => 'web', 'hostname' => 'app.example.com'],
        ['type' => 'flutter-web', 'hostname' => 'flutter.example.com'],
        ['type' => 'android', 'key' => 'com.example.android'],
        ['type' => 'flutter-ios', 'key' => 'com.example.ios'],
        ['type' => 'apple-macos', 'key' => 'com.example.macos'],
        ['type' => 'windows', 'key' => 'Example.Windows'],
        ['type' => 'react-native-android', 'key' => 'com.example.rn'],
        ['type' => 'scheme', 'key' => 'example-app'],
    ];

    public function testHostnamesIncludeNativeAppIdsForOrigins(): void
    {
        $this->assertSame([
            'app.example.com',
            'flutter.example.com',
            'com.example.android',
            'com.example.ios',
            'com.example.macos',
            'example.windows',
            'com.example.rn',
        ], \array_values(Platform::getHostnames(self::PLATFORMS)));
    }

    public function testRedirectHostnamesLeaveNativeAppIdsOut(): void
    {
        $this->assertSame(['app.example.com', 'flutter.example.com'], \array_values(Platform::getHostnames(self::PLATFORMS, appIds: false)));
    }

    public function testWebHostnameThatEqualsAnAppIdStaysForRedirects(): void
    {
        $platforms = [
            ['type' => 'android', 'key' => 'app.example.com'],
            ['type' => 'web', 'hostname' => 'app.example.com'],
        ];

        $this->assertSame(['app.example.com'], \array_values(Platform::getHostnames($platforms, appIds: false)));
    }
}
