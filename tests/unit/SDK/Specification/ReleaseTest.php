<?php

declare(strict_types=1);

namespace Tests\Unit\SDK\Specification;

use Appwrite\SDK\Specification\Release;
use PHPUnit\Framework\TestCase;

final class ReleaseTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir() . '/release-test-' . \bin2hex(\random_bytes(4));
        \mkdir($this->directory);
        \file_put_contents($this->directory . '/open-api3-1.9.x-server.json', \json_encode([
            'openapi' => '3.0.0',
            'info' => ['title' => 'Appwrite', 'version' => '2.5.0'],
            'paths' => [],
        ]));
    }

    protected function tearDown(): void
    {
        \unlink($this->directory . '/open-api3-1.9.x-server.json');
        \rmdir($this->directory);
    }

    public function testStaticSpecificationReportsTheServerApiVersion(): void
    {
        $specification = (new Release($this->directory, '1.9.x'))->staticSpecification();

        $this->assertSame('2.5.0', $specification->info->version);
    }

    public function testReadReturnsTheFamilySpec(): void
    {
        $spec = \json_decode((new Release($this->directory, '1.9.x'))->read('server'), true);

        $this->assertSame('2.5.0', $spec['info']['version']);
    }

    public function testMissingSpecsAskForTheSpecsToBeGenerated(): void
    {
        $this->expectExceptionMessage('Spec file not found');

        (new Release($this->directory, '2.0.x'))->staticSpecification();
    }
}
