<?php

namespace Appwrite\Tests;

use Appwrite\Resize\Resize;
use PHPUnit\Framework\TestCase;

class ResizeTest extends TestCase
{
    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    public function testCrop100x100()
    {
        $resize = new Resize(\file_get_contents(__DIR__ . '/../../resources/disk-a/kitten-1.jpg'));
        $target = __DIR__.'/100x100.jpg';

        $resize->crop(100, 100);

        $resize->save($target, 'jpg', 100);

        $this->assertEquals(\is_readable($target), true);
        $this->assertNotEmpty(\md5(\file_get_contents($target)));

        $image = new \Imagick($target);
        $this->assertEquals(100, $image->getImageWidth());
        $this->assertEquals(100, $image->getImageHeight());
        $this->assertEquals('JPEG', $image->getImageFormat());

        \unlink($target);
    }

    public function testCrop100x400()
    {
        $resize = new Resize(\file_get_contents(__DIR__ . '/../../resources/disk-a/kitten-1.jpg'));
        $target = __DIR__.'/100x400.jpg';

        $resize->crop(100, 400);

        $resize->save($target, 'jpg', 100);

        $this->assertEquals(\is_readable($target), true);
        $this->assertNotEmpty(\md5(\file_get_contents($target)));

        $image = new \Imagick($target);
        $this->assertEquals(100, $image->getImageWidth());
        $this->assertEquals(400, $image->getImageHeight());
        $this->assertEquals('JPEG', $image->getImageFormat());

        \unlink($target);
    }

    public function testCrop400x100()
    {
        $resize = new Resize(\file_get_contents(__DIR__ . '/../../resources/disk-a/kitten-1.jpg'));
        $target = __DIR__.'/400x100.jpg';

        $resize->crop(400, 100);

        $resize->save($target, 'jpg', 100);

        $this->assertEquals(\is_readable($target), true);
        $this->assertNotEmpty(\md5(\file_get_contents($target)));

        $image = new \Imagick($target);
        $this->assertEquals(400, $image->getImageWidth());
        $this->assertEquals(100, $image->getImageHeight());
        $this->assertEquals('JPEG', $image->getImageFormat());

        \unlink($target);
    }

    public function testCrop100x100WEBP()
    {
        $resize = new Resize(\file_get_contents(__DIR__ . '/../../resources/disk-a/kitten-1.jpg'));
        $target = __DIR__.'/100x100.webp';
        $original = __DIR__.'/../../resources/resize/100x100.webp';

        $resize->crop(100, 100);

        $resize->save($target, 'webp', 100);

        $this->assertEquals(\is_readable($target), true);
        $this->assertNotEmpty(\md5(\file_get_contents($target)));

        $image = new \Imagick($target);

        $this->assertEquals(100, $image->getImageWidth());
        $this->assertEquals(100, $image->getImageHeight());
        $this->assertTrue(in_array($image->getImageFormat(), ['PAM', 'WEBP']));

        \unlink($target);
    }

    public function testCrop100x100PNG()
    {
        $resize = new Resize(\file_get_contents(__DIR__ . '/../../resources/disk-a/kitten-1.jpg'));
        $target = __DIR__.'/100x100.png';
        $original = __DIR__.'/../../resources/resize/100x100.png';

        $resize->crop(100, 100);

        $resize->save($target, 'png', 100);

        $this->assertEquals(\is_readable($target), true);
        $this->assertGreaterThan(15000, \filesize($target));
        $this->assertLessThan(30000, \filesize($target));
        $this->assertEquals(\mime_content_type($target), \mime_content_type($original));
        $this->assertNotEmpty(\md5(\file_get_contents($target)));

        $image = new \Imagick($target);
        $this->assertEquals(100, $image->getImageWidth());
        $this->assertEquals(100, $image->getImageHeight());
        $this->assertEquals('PNG', $image->getImageFormat());

        \unlink($target);
    }

    public function testCrop100x100PNGQuality30()
    {
        $resize = new Resize(\file_get_contents(__DIR__ . '/../../resources/disk-a/kitten-1.jpg'));
        $target = __DIR__.'/100x100-q30.jpg';
        $original = __DIR__.'/../../resources/resize/100x100-q30.jpg';

        $resize->crop(100, 100);

        $resize->save($target, 'jpg', 10);

        $this->assertEquals(\is_readable($target), true);
        $this->assertGreaterThan(500, \filesize($target));
        $this->assertLessThan(2000, \filesize($target));
        $this->assertEquals(\mime_content_type($target), \mime_content_type($original));
        $this->assertNotEmpty(\md5(\file_get_contents($target)));

        $image = new \Imagick($target);
        $this->assertEquals(100, $image->getImageWidth());
        $this->assertEquals(100, $image->getImageHeight());
        $this->assertEquals('JPEG', $image->getImageFormat());

        \unlink($target);
    }

    public function testCrop100x100GIF()
    {
        $resize = new Resize(\file_get_contents(__DIR__ . '/../../resources/disk-a/kitten-3.gif'));
        $target = __DIR__.'/100x100.gif';
        $original = __DIR__.'/../../resources/resize/100x100.gif';

        $resize->crop(100, 100);

        $resize->save($target, 'gif', 100);

        $this->assertEquals(\is_readable($target), true);
        $this->assertGreaterThan(400000, \filesize($target));
        $this->assertLessThan(800000, \filesize($target));
        $this->assertEquals(\mime_content_type($target), \mime_content_type($original));
        $this->assertNotEmpty(\md5(\file_get_contents($target)));

        $image = new \Imagick($target);
        $this->assertEquals(100, $image->getImageWidth());
        $this->assertEquals(100, $image->getImageHeight());
        $this->assertEquals('GIF', $image->getImageFormat());
        \unlink($target);
    }
}
