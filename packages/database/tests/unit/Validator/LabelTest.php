<?php

namespace Tests\Unit\Validator;

use PHPUnit\Framework\TestCase;
use Utopia\Database\Validator\Label;

class LabelTest extends TestCase
{
    /**
     * @var Label
     */
    protected ?Label $object = null;

    public function setUp(): void
    {
        $this->object = new Label();
    }

    public function tearDown(): void
    {
    }

    public function testValues(): void
    {
        // Must be strings
        $this->assertEquals(false, $this->object->isValid(false));
        $this->assertEquals(false, $this->object->isValid(null));
        $this->assertEquals(false, $this->object->isValid(['value']));
        $this->assertEquals(false, $this->object->isValid(0));
        $this->assertEquals(false, $this->object->isValid(1.5));
        $this->assertEquals(true, $this->object->isValid('asdas7as9as'));
        $this->assertEquals(true, $this->object->isValid('5f058a8925807'));

        // No special chars
        $this->assertEquals(false, $this->object->isValid('_asdasdasdas'));
        $this->assertEquals(false, $this->object->isValid('.as5dasdasdas'));
        $this->assertEquals(false, $this->object->isValid('-as5dasdasdas'));
        $this->assertEquals(false, $this->object->isValid('as5dadasdas_'));
        $this->assertEquals(false, $this->object->isValid('as_5dasdasdas'));
        $this->assertEquals(false, $this->object->isValid('as5dasdasdas.'));
        $this->assertEquals(false, $this->object->isValid('as.5dasdasdas'));
        $this->assertEquals(false, $this->object->isValid('as5dasdasdas-'));
        $this->assertEquals(false, $this->object->isValid('as-5dasdasdas'));
        $this->assertEquals(false, $this->object->isValid('dasda asdasd'));
        $this->assertEquals(false, $this->object->isValid('asd"asd6sdas'));
        $this->assertEquals(false, $this->object->isValid('asd\'as0asdas'));
        $this->assertEquals(false, $this->object->isValid('as!5dasdasdas'));
        $this->assertEquals(false, $this->object->isValid('as@5dasdasdas'));
        $this->assertEquals(false, $this->object->isValid('as#5dasdasdas'));
        $this->assertEquals(false, $this->object->isValid('as$5dasdasdas'));
        $this->assertEquals(false, $this->object->isValid('as%5dasdasdas'));
        $this->assertEquals(false, $this->object->isValid('as^5dasdasdas'));
        $this->assertEquals(false, $this->object->isValid('as&5dasdasdas'));
        $this->assertEquals(false, $this->object->isValid('as*5dasdasdas'));
        $this->assertEquals(false, $this->object->isValid('as(5dasdasdas'));
        $this->assertEquals(false, $this->object->isValid('as)5dasdasdas'));
        $this->assertEquals(false, $this->object->isValid('as+5dasdasdas'));
        $this->assertEquals(false, $this->object->isValid('as=5dasdasdas'));

        // At most 255 chars
        $this->assertEquals(true, $this->object->isValid(str_repeat('a', 36)));
        $this->assertEquals(false, $this->object->isValid(str_repeat('a', 256)));
    }
}
