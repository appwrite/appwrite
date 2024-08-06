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
    }
}
