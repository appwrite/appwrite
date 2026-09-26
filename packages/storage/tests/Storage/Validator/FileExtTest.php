<?php

declare(strict_types=1);

namespace Utopia\Tests\Storage\Validator;

use PHPUnit\Framework\TestCase;
use Utopia\Storage\Validator\FileExt;

final class FileExtTest extends TestCase
{
    private \Utopia\Storage\Validator\FileExt $object;

    protected function setUp(): void
    {
        $this->object = new FileExt([FileExt::TYPE_GIF, FileExt::TYPE_GZIP, FileExt::TYPE_ZIP]);
    }

    protected function tearDown(): void {}

    public function testValues(): void
    {
        $this->assertEquals(false, $this->object->isValid(''));
        $this->assertEquals(false, $this->object->isValid(null));
        $this->assertEquals(false, $this->object->isValid(false));
        $this->assertEquals(false, $this->object->isValid('test'));
        $this->assertEquals(false, $this->object->isValid('...test.png'));
        $this->assertEquals(true, $this->object->isValid('.gif'));
        $this->assertEquals(true, $this->object->isValid('x.gif'));
        $this->assertEquals(false, $this->object->isValid('gif'));
        $this->assertEquals(false, $this->object->isValid('file.tar'));
        $this->assertEquals(false, $this->object->isValid('file.tar.g'));
        $this->assertEquals(true, $this->object->isValid('file.tar.gz'));
        $this->assertEquals(true, $this->object->isValid('file.gz'));
        $this->assertEquals(true, $this->object->isValid('file.GIF'));
        $this->assertEquals(true, $this->object->isValid('file.zip'));
        $this->assertEquals(false, $this->object->isValid('file.7zip'));
    }
}
