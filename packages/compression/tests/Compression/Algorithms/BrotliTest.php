<?php

declare(strict_types=1);

namespace Utopia\Tests\Compression\Algorithms;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Utopia\Compression\Algorithms\Brotli;

#[RequiresPhpExtension('brotli')]
final class BrotliTest extends TestCase
{
    /**
     * @var Brotli
     */
    protected $object;

    public function setUp(): void
    {
        $this->object = new Brotli();
    }

    public function tearDown(): void {}

    public function testName(): void
    {
        $this->assertEquals('brotli', $this->object->getName());
    }

    public function testErrorsWhenSettingLevel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->object->setLevel(-1);
    }

    public function testCompressDecompressWithText(): void
    {
        $demo = 'This is a demo string';
        $demoSize = mb_strlen($demo, '8bit');

        $data = $this->object->compress($demo);

        $this->assertSame(21, $demoSize);

        $this->assertEquals($this->object->decompress($data), $demo);
    }

    public function testCompressDecompressWithLargeText(): void
    {
        $demo = file_get_contents(__DIR__ . '/../../resources/disk-a/lorem.txt');
        $demoSize = mb_strlen($demo, '8bit');

        $this->object->setLevel(8);
        $data = $this->object->compress($demo);
        $dataSize = mb_strlen($data, '8bit');

        $this->assertSame(386795, $demoSize);

        $this->assertGreaterThan($dataSize, $demoSize);

        $data = $this->object->decompress($data);
        $dataSize = mb_strlen($data, '8bit');

        $this->assertSame(386795, $dataSize);
        $this->assertEquals($data, $demo);
    }

    public function testCompressDecompressWithJPGImage(): void
    {
        $demo = file_get_contents(__DIR__ . '/../../resources/disk-a/kitten-1.jpg');
        $demoSize = mb_strlen($demo, '8bit');

        $this->object->setLevel(8);
        $data = $this->object->compress($demo);
        $dataSize = mb_strlen($data, '8bit');

        $this->assertSame(599639, $demoSize);
        // brotli is not the best for images

        $data = $this->object->decompress($data);
        $dataSize = mb_strlen($data, '8bit');

        $this->assertSame(599639, $dataSize);
    }

    public function testCompressDecompressWithPNGImage(): void
    {
        $demo = file_get_contents(__DIR__ . '/../../resources/disk-b/kitten-1.png');
        $demoSize = mb_strlen($demo, '8bit');

        $this->object->setLevel(8);
        $data = $this->object->compress($demo);
        $dataSize = mb_strlen($data, '8bit');

        $this->assertSame(3038056, $demoSize);
        // brotli is not the best for images

        $data = $this->object->decompress($data);
        $dataSize = mb_strlen($data, '8bit');

        $this->assertSame(3038056, $dataSize);
    }
}
