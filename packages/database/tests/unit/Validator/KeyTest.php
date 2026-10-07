<?php

namespace Tests\Unit\Validator;

use PHPUnit\Framework\TestCase;
use Utopia\Database\Validator\Key;

class KeyTest extends TestCase
{
    /**
     * @var Key
     */
    protected ?Key $object = null;

    public function setUp(): void
    {
        $this->object = new Key();
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

        // Don't allow empty string
        $this->assertEquals(false, $this->object->isValid(''));
        $this->assertEquals(true, $this->object->isValid('0'));
        $this->assertEquals(true, $this->object->isValid('null'));

        // No leading special chars
        $this->assertEquals(false, $this->object->isValid('_asdasdasdas'));
        $this->assertEquals(false, $this->object->isValid('.as5dasdasdas'));
        $this->assertEquals(false, $this->object->isValid('-as5dasdasdas'));

        // Special chars allowed: underscore, period, hyphen
        $this->assertEquals(true, $this->object->isValid('as5dadasdas_'));
        $this->assertEquals(true, $this->object->isValid('as_5dasdasdas'));
        $this->assertEquals(true, $this->object->isValid('as5dasdasdas.'));
        $this->assertEquals(true, $this->object->isValid('as.5dasdasdas'));
        $this->assertEquals(true, $this->object->isValid('as5dasdasdas-'));
        $this->assertEquals(true, $this->object->isValid('as-5dasdasdas'));

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

        // At most 36 chars
        $this->assertEquals(true, $this->object->isValid(str_repeat('a', 36)));
        $this->assertEquals(false, $this->object->isValid(str_repeat('a', 256)));

        // Internal keys
        $validator = new Key(true);
        $this->assertEquals(true, $validator->isValid('appwrite'));
        $this->assertEquals(true, $validator->isValid('appwrite_'));
        $this->assertEquals(false, $validator->isValid('_appwrite'));
        $this->assertEquals(false, $validator->isValid('_'));

        $this->assertEquals(true, $validator->isValid('$id'));
        $this->assertEquals(true, $validator->isValid('$createdAt'));
        $this->assertEquals(true, $validator->isValid('$updatedAt'));

        $this->assertEquals(false, $validator->isValid('$appwrite'));
        $this->assertEquals(false, $validator->isValid('$permissions'));
        $this->assertEquals(false, $validator->isValid('$'));
    }
}
