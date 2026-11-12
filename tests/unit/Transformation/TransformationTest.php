<?php

declare(strict_types=1);

namespace Tests\Unit\Transformation;

use Appwrite\Transformation\Adapter\Mock;
use Appwrite\Transformation\Adapter\Preview;
use Appwrite\Transformation\Transformation;
use PHPUnit\Framework\TestCase;

final class TransformationTest extends TestCase
{
    public function testPreview(): void
    {
        $input = "Hello world";

        $transformer = new Transformation([new Preview()]);
        $transformer->addAdapter(new Mock());

        $transformer->setInput($input);
        $transformer->setTraits([]);

        $this->assertFalse($transformer->transform());

        $transformer->setTraits(['mock' => false, 'content-type' => 'text/plain']);
        $this->assertFalse($transformer->transform());

        $transformer->setTraits(['mock' => true, 'content-type' => 'text/plain, text/html; charset=utf-8']);
        $this->assertTrue($transformer->transform());

        $this->assertStringContainsString("Hello world", (string) $transformer->getOutput());
        $this->assertStringContainsString("Preview by", (string) $transformer->getOutput());
        $this->assertStringContainsString("Mock:", (string) $transformer->getOutput());
    }

    public function testInvalidAdapterIsSkipped(): void
    {
        $transformer = new Transformation([new Preview(), new Mock()]);
        $transformer->setInput("Hello world");

        $transformer->setTraits(['mock' => true, 'content-type' => 'text/plain']);
        $this->assertTrue($transformer->transform());
        $this->assertSame("Mock: Hello world", $transformer->getOutput());

        $transformer->setTraits(['mock' => false, 'content-type' => 'text/html']);
        $this->assertTrue($transformer->transform());
        $this->assertStringStartsWith("Hello world", (string) $transformer->getOutput());
        $this->assertStringContainsString("Preview by", (string) $transformer->getOutput());
        $this->assertStringNotContainsString("Mock:", (string) $transformer->getOutput());
    }

    public function testNothingValidKeepsInput(): void
    {
        $transformer = new Transformation([new Mock(), new Preview()]);
        $transformer->setInput("Hello world");
        $transformer->setTraits(['content-type' => 'tExT/HtML']);

        $this->assertFalse($transformer->transform());
        $this->assertSame("Hello world", $transformer->getOutput());
    }

    public function testNoAdapters(): void
    {
        $transformer = new Transformation();
        $transformer->setInput("Hello world");
        $transformer->setTraits(['content-type' => 'text/html']);

        $this->assertFalse($transformer->transform());
        $this->assertSame("Hello world", $transformer->getOutput());
    }
}
