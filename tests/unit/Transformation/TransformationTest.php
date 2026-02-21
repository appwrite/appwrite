<?php

namespace Tests\Unit\Transformation;

use Appwrite\Transformation\Adapter\Mock;
use Appwrite\Transformation\Adapter\Preview;
use Appwrite\Transformation\Transformation;
use PHPUnit\Framework\TestCase;

class TransformationTest extends TestCase
{
    public function testPreview(): void
    {
        $input = "Hello world";

        $transformer = new Transformation([new Preview()]);
        $transformer->addAdapter(new Mock());

        $transformer->setInput($input);
        $transformer->setTraits([]);

        $this->assertFalse($transformer->transform());

        $transformer->setTraits(['mock' => true]);
        $this->assertFalse($transformer->transform());

        $transformer->setTraits(['mock' => true, 'content-type' => 'text/plain']);
        $this->assertFalse($transformer->transform());

        $transformer->setTraits(['mock' => true, 'content-type' => 'tExT/HtML']);
        $this->assertFalse($transformer->transform());

        $transformer->setTraits(['mock' => false, 'content-type' => 'text/plain, text/html; charset=utf-8']);
        $this->assertFalse($transformer->transform());

        $transformer->setTraits(['mock' => true, 'content-type' => 'text/plain, text/html; charset=utf-8']);
        $this->assertTrue($transformer->transform());

        $this->assertStringContainsString("Hello world", $transformer->getOutput());
        $this->assertStringContainsString("Preview by", $transformer->getOutput());
        $this->assertStringContainsString("Mock:", $transformer->getOutput());
    }
}
