<?php

declare(strict_types=1);

namespace Utopia\Tests\Storage\Validator;

use PHPUnit\Framework\TestCase;
use Utopia\Storage\Validator\FileSize;

final class FileSizeTest extends TestCase
{
    private \Utopia\Storage\Validator\FileSize $object;

    protected function setUp(): void
    {
        $this->object = new FileSize(1000);
    }

    protected function tearDown(): void {}

    public function testValues(): void
    {
        $this->assertEquals(false, $this->object->isValid(1001));
        $this->assertEquals(true, $this->object->isValid(1000));
        $this->assertEquals(true, $this->object->isValid(999));
    }
}
