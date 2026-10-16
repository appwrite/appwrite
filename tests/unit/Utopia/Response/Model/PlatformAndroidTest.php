<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Response as SwooleResponse;
use Utopia\Database\Document;

final class PlatformAndroidTest extends TestCase
{
    public function testPlatformWithoutFingerprintsOutputsEmptyList(): void
    {
        $output = (new Response(new SwooleResponse()))->output(new Document([
            '$id' => 'platform',
            '$createdAt' => '2026-01-01T00:00:00.000+00:00',
            '$updatedAt' => '2026-01-01T00:00:00.000+00:00',
            'name' => 'Android',
            'type' => 'android',
            'key' => 'com.example.app',
        ]), Response::MODEL_PLATFORM_ANDROID);

        $this->assertSame('com.example.app', $output['applicationId']);
        $this->assertSame([], $output['fingerprints']);
    }
}
