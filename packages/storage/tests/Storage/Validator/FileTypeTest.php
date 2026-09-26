<?php

declare(strict_types=1);

namespace Utopia\Tests\Storage\Validator;

use PHPUnit\Framework\TestCase;
use Utopia\Storage\Validator\FileType;

final class FileTypeTest extends TestCase
{
    private \Utopia\Storage\Validator\FileType $object;

    protected function setUp(): void
    {
        $this->object = new FileType([FileType::FILE_TYPE_JPEG]);
    }

    protected function tearDown(): void {}

    public function testValues(): void
    {
        $this->assertEquals(true, $this->object->isValid(__DIR__ . '/../../resources/disk-a/kitten-1.jpg'));
        $this->assertEquals(true, $this->object->isValid(__DIR__ . '/../../resources/disk-a/kitten-2.jpg'));
        $this->assertEquals(false, $this->object->isValid(__DIR__ . '/../../resources/disk-b/kitten-1.png'));
        $this->assertEquals(false, $this->object->isValid(__DIR__ . '/../../resources/disk-b/kitten-2.png'));
    }
}
