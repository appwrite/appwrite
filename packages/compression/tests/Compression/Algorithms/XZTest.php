<?php

declare(strict_types=1);

namespace Utopia\Tests\Compression\Algorithms;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Utopia\Compression\Algorithms\XZ;

#[RequiresPhpExtension('xz')]
final class XZTest extends TestCase
{
    protected XZ $object;

    public function setUp(): void
    {
        $this->object = new XZ();
    }

    public function tearDown(): void {}

    public function testName(): void
    {
        $this->assertSame('xz', $this->object->getName());
    }

    public function testCompressDecompressWithText(): void
    {
        $demo = 'This is a demo string';
        $demoSize = mb_strlen($demo, '8bit');

        $data = $this->object->compress($demo);

        $this->assertSame(21, $demoSize);

        $this->assertSame($this->object->decompress($data), $demo);
    }

    public function testCompressDecompressWithJPGImage(): void
    {
        $demo = file_get_contents(__DIR__ . '/../../resources/disk-a/kitten-1.jpg');
        $demoSize = mb_strlen($demo, '8bit');

        $data = $this->object->compress($demo);
        $dataSize = mb_strlen($data, '8bit');

        $this->assertSame(599639, $demoSize);

        $this->assertGreaterThan($dataSize, $demoSize);

        $data = $this->object->decompress($data);
        $dataSize = mb_strlen($data, '8bit');

        $this->assertSame(599639, $dataSize);
    }

    public function testCompressDecompressWithPNGImage(): void
    {
        $demo = file_get_contents(__DIR__ . '/../../resources/disk-b/kitten-1.png');
        $demoSize = mb_strlen($demo, '8bit');

        $data = $this->object->compress($demo);
        $dataSize = mb_strlen($data, '8bit');

        $this->assertSame(3038056, $demoSize);

        $this->assertGreaterThan($dataSize, $demoSize);

        $data = $this->object->decompress($data);
        $dataSize = mb_strlen($data, '8bit');

        $this->assertSame(3038056, $dataSize);
    }
}
