<?php

namespace Tests\Unit\Functions\Validator;

use Appwrite\Functions\Validator\Headers;
use PHPUnit\Framework\TestCase;

class HeadersTest extends TestCase
{
    protected ?Headers $object = null;

    public function setUp(): void
    {
        $this->object = new Headers();
    }

    public function testValues(): void
    {
        $headers = [
            'headerKey' => 'headerValue',
        ];
        $this->assertEquals($this->object->isValid($headers), true);

        $headers = [
            'headerKey' => 'headerValue',
            'x-appwrite-key' => 'headerValue',
        ];
        $this->assertEquals($this->object->isValid($headers), false);

        $headers = [
            'headerKey' => 'headerValue',
            'headerKey2' => 'headerValue2',
        ];
        $this->assertEquals($this->object->isValid($headers), true);

        $headers = [
            'headerKey' => 'headerValue',
            'x-appwrite-project' => 'headerValue',
            'headerKey2' => 'headerValue2',
        ];
        $this->assertEquals($this->object->isValid($headers), false);

        $headers = [
            'header/////Key' => 'headerValue',
        ];
        $this->assertEquals($this->object->isValid($headers), false);

        $headers = [
            'Content-Type' => 'application/json',
            'X-Custom-Header' => 'value'
        ];
        $this->assertEquals($this->object->isValid($headers), true);

        $headers = [
            'X-Custom-Header_With-Hyphens_and_Underscores' => 'value'
        ];
        $this->assertFalse($this->object->isValid($headers));

        $headers = [
            'X-Header-123' => 'value'
        ];
        $this->assertTrue($this->object->isValid($headers));

        $headers = [
            'X-Header<>' => 'value'
        ];
        $this->assertFalse($this->object->isValid($headers));

        $headers = [
            'X Header' => 'value'
        ];
        $this->assertFalse($this->object->isValid($headers));

        $headers = [
            '' => 'value'
        ];
        $this->assertFalse($this->object->isValid($headers));

        $headers = [
            null => 'value',
        ];
        $this->assertFalse($this->object->isValid($headers));

        $headers = [
            'X-Header' => null,
        ];
        $this->assertTrue($this->object->isValid($headers));

        $headers = [
            true => 'value',
        ];
        $this->assertFalse($this->object->isValid($headers));

        $headers = [
            'a' => 'b',
        ];
        $this->assertTrue($this->object->isValid($headers));
    }
}
