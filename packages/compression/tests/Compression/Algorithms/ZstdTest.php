<?php

declare(strict_types=1);

namespace Utopia\Tests\Compression\Algorithms;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Utopia\Compression\Algorithms\Zstd;

#[RequiresPhpExtension('zstd')]
final class ZstdTest extends TestCase
{
    protected Zstd $object;

    public function setUp(): void
    {
        $this->object = new Zstd();
    }

    public function tearDown(): void {}

    public function testName(): void
    {
        $this->assertSame('zstd', $this->object->getName());
    }

    public function testCompressDecompressWithText(): void
    {
        $demo = 'This is a demo string';
        $demoSize = mb_strlen($demo, '8bit');

        $data = $this->object->compress($demo);
        $dataSize = mb_strlen($data, '8bit');

        $this->assertSame(21, $demoSize);
        $this->assertSame(30, $dataSize);

        $this->assertSame($demo, $this->object->decompress($data));
    }

    public function testCompressDecompressWithLargeText(): void
    {
        $demo = file_get_contents(__DIR__ . '/../../resources/disk-a/lorem.txt');
        $demoSize = mb_strlen($demo, '8bit');

        $data = $this->object->compress($demo);
        $dataSize = mb_strlen($data, '8bit');

        $this->assertSame(386795, $demoSize);

        $this->assertGreaterThan($dataSize, $demoSize);

        $data = $this->object->decompress($data);
        $dataSize = mb_strlen($data, '8bit');

        $this->assertSame(386795, $dataSize);
    }

    public function testCompressDecompressWithJPGImage(): void
    {
        $demo = file_get_contents(__DIR__ . '/../../resources/disk-a/kitten-1.jpg');
        $demoSize = mb_strlen($demo, '8bit');

        $data = $this->object->compress($demo);
        $dataSize = mb_strlen($data, '8bit');

        $this->assertSame(599639, $demoSize);
        $this->assertSame(599663, $dataSize);

        $this->assertGreaterThan($demoSize, $dataSize);

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
        $this->assertSame(3038138, $dataSize);

        $this->assertGreaterThan($demoSize, $dataSize);

        $data = $this->object->decompress($data);
        $dataSize = mb_strlen($data, '8bit');

        $this->assertSame(3038056, $dataSize);
    }
}
