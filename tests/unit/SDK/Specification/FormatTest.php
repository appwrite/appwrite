<?php

namespace Tests\Unit\SDK\Specification;

use Appwrite\SDK\Specification\Format;
use PHPUnit\Framework\TestCase;
use Utopia\DI\Container;

class TestFormat extends Format
{
    public function getName(): string
    {
        return 'test';
    }

    public function parse(): array
    {
        return [];
    }

    public function requestParameterConfig(string $service, string $method, string $param, bool $optional, bool $nullable, mixed $default): array
    {
        return $this->getRequestParameterConfig($service, $method, $param, $optional, $nullable, $default);
    }
}

class FormatTest extends TestCase
{
    private TestFormat $format;

    protected function setUp(): void
    {
        parent::setUp();

        $this->format = new TestFormat(new Container(), [], [], [], [], 0, 'console');
    }

    public function testProjectRequestParameterOverrides(): void
    {
        $createWebPlatform = $this->format->requestParameterConfig('project', 'createWebPlatform', 'hostname', true, false, '');
        $updateWebPlatform = $this->format->requestParameterConfig('project', 'updateWebPlatform', 'hostname', true, false, '');
        $listPlatforms = $this->format->requestParameterConfig('project', 'listPlatforms', 'queries', true, false, []);

        $this->assertTrue($createWebPlatform['required']);
        $this->assertFalse($createWebPlatform['emitDefault']);
        $this->assertTrue($updateWebPlatform['required']);
        $this->assertFalse($updateWebPlatform['emitDefault']);
        $this->assertTrue($listPlatforms['emitDefault']);
    }

    public function testProjectPlatformResponseTypeUsesSharedEnumName(): void
    {
        $this->assertSame('PlatformType', $this->format->getResponseEnumName('platformAndroid', 'type'));
        $this->assertSame('PlatformType', $this->format->getResponseEnumName('platformWeb', 'type'));
        $this->assertSame('PlatformType', $this->format->getResponseEnumName('platformApple', 'type'));
        $this->assertSame('PlatformType', $this->format->getResponseEnumName('platformWindows', 'type'));
        $this->assertSame('PlatformType', $this->format->getResponseEnumName('platformLinux', 'type'));
        $this->assertNull($this->format->getResponseEnumName('platformList', 'type'));
    }

    public function testExistingResponseEnumMappingsRemainUnchanged(): void
    {
        $this->assertSame('HealthCheckStatus', $this->format->getResponseEnumName('healthStatus', 'status'));
        $this->assertNull($this->format->getResponseEnumName('key', 'name'));
    }
}
