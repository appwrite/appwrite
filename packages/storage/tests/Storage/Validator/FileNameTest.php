<?php

declare(strict_types=1);

namespace Utopia\Tests\Storage\Validator;

use PHPUnit\Framework\TestCase;
use Utopia\Storage\Validator\FileName;

final class FileNameTest extends TestCase
{
    private \Utopia\Storage\Validator\FileName $object;

    protected function setUp(): void
    {
        $this->object = new FileName();
    }

    protected function tearDown(): void {}

    public function testValues(): void
    {
        $this->assertEquals(false, $this->object->isValid(''));
        $this->assertEquals(false, $this->object->isValid(null));
        $this->assertEquals(false, $this->object->isValid(false));
        $this->assertEquals(false, $this->object->isValid('../test'));
        $this->assertEquals(true, $this->object->isValid('test.png'));
        $this->assertEquals(true, $this->object->isValid('test'));
        $this->assertEquals(true, $this->object->isValid('test-test'));
        $this->assertEquals(true, $this->object->isValid('test_test'));
        $this->assertEquals(true, $this->object->isValid('test.test-test_test'));
    }
}
