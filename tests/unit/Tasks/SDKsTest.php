<?php

declare(strict_types=1);

namespace Tests\Unit\Tasks;

use Appwrite\Platform\Tasks\SDKs;
use PHPUnit\Framework\TestCase;

final class SDKsTest extends TestCase
{
    private string $specs;

    protected function setUp(): void
    {
        $this->specs = \sys_get_temp_dir() . '/sdks-test-' . \bin2hex(\random_bytes(4));
        \mkdir($this->specs);
        \file_put_contents($this->specs . '/open-api3-1.9.x-server.json', \json_encode([
            'openapi' => '3.0.0',
            'info' => ['title' => 'Appwrite', 'version' => '2.5.0'],
            'paths' => [],
        ]));
    }

    protected function tearDown(): void
    {
        \unlink($this->specs . '/open-api3-1.9.x-server.json');
        \rmdir($this->specs);
    }

    public function testStaticSdksReportTheServerApiVersion(): void
    {
        $specification = SDKs::staticSpecification($this->specs, '1.9.x');

        $this->assertSame('2.5.0', $specification->info->version);
    }

    public function testStaticSdksNeedTheServerSpec(): void
    {
        $this->expectExceptionMessage('Spec file not found');

        SDKs::staticSpecification($this->specs, '2.0.x');
    }
}
