<?php

namespace Tests\Unit\Transformation;

use Appwrite\Transformation\Adapter\Preview;
use Appwrite\Transformation\Transformation;
use PHPUnit\Framework\TestCase;

class TransformationTest extends TestCase
{
    public function testPreview(): void
    {
        $transformer = new Transformation(new Preview('Hello world'));

        $this->assertFalse($transformer->isValid([]));
        $this->assertFalse($transformer->isValid(['content-type' => 'text/plain']));
        $this->assertFalse($transformer->isValid(['content-type' => 'tExT/HtML']));
        $this->assertTrue($transformer->isValid(['content-type' => 'text/html']));
        $this->assertTrue($transformer->isValid(['content-TYPE' => 'text/html']));
        $this->assertTrue($transformer->isValid(['content-TYPE' => 'text/plain, text/html; charset=utf-8']));

        $this->assertTrue($transformer->transform());

        $this->assertStringContainsString("Hello world", $transformer->getOutput());
        $this->assertStringContainsString("<script defer", $transformer->getOutput());
    }
}
