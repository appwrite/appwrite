<?php

namespace Appwrite\Tests;

use Storage\Validators\FileType;
use PHPUnit\Framework\TestCase;

class FileTypeTest extends TestCase
{
    /**
     * @var FileType
     */
    protected $object = null;

    public function setUp()
    {
        $this->object = new FileType([FileType::FILE_TYPE_JPEG]);
    }

    public function tearDown()
    {
    }

    public function testValues()
    {
        $this->assertEquals($this->object->isValid(__DIR__ . '/../../../resources/disk-a/kitten-1.jpg'), true);
        $this->assertEquals($this->object->isValid(__DIR__ . '/../../../resources/disk-a/kitten-2.jpg'), true);
        $this->assertEquals($this->object->isValid(__DIR__ . '/../../../resources/disk-a/kitten-2.jpg'), true);
    }
}