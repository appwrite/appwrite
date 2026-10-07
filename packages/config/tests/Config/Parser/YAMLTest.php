<?php

declare(strict_types=1);

namespace Utopia\Tests\Parser;

use PHPUnit\Framework\TestCase;
use Utopia\Config\Exception\Parse;
use Utopia\Config\Parser\YAML;

final class YAMLTest extends TestCase
{
    protected YAML $parser;

    protected function setUp(): void
    {
        $this->parser = new YAML();
    }

    public function testYAMLBasicTypes(): void
    {
        $yaml = <<<YAML
          string: hello world
          unicode_string: ä你こحب🌍
          integer: 42
          float: 3.14159
          negative: -50
          boolean_true: true
          boolean_false: false
          null_value: null
        YAML;

        $data = $this->parser->parse($yaml);

        $this->assertSame('hello world', $data['string']);
        $this->assertSame('ä你こحب🌍', $data['unicode_string']);
        $this->assertSame(42, $data['integer']);
        $this->assertEqualsWithDelta(3.14159, $data['float'], PHP_FLOAT_EPSILON);
        $this->assertSame(-50, $data['negative']);
        $this->assertTrue($data['boolean_true']);
        $this->assertFalse($data['boolean_false']);
        $this->assertNull($data['null_value']);
    }

    public function testYAMLArray(): void
    {
        $yaml = <<<YAML
          simple_array:
            - 1
            - 2
            - 3
            - 4
            - 5
          mixed_array:
            - string
            - 42
            - true
            - null
            - 3.14
          nested_array:
            - [1, 2, 3]
            - [a, b, c, d]
            - [true, false]
          empty_array: []
        YAML;

        $data = $this->parser->parse($yaml);

        $this->assertCount(5, $data['simple_array']);
        $this->assertSame(1, $data['simple_array'][0]);
        $this->assertSame(5, $data['simple_array'][4]);

        $this->assertCount(5, $data['mixed_array']);
        $this->assertSame('string', $data['mixed_array'][0]);
        $this->assertSame(42, $data['mixed_array'][1]);
        $this->assertTrue($data['mixed_array'][2]);
        $this->assertNull($data['mixed_array'][3]);
        $this->assertEqualsWithDelta(3.14, $data['mixed_array'][4], PHP_FLOAT_EPSILON);

        $this->assertCount(3, $data['nested_array']);

        $this->assertCount(3, $data['nested_array'][0]);
        $this->assertCount(4, $data['nested_array'][1]);
        $this->assertCount(2, $data['nested_array'][2]);

        $this->assertSame(2, $data['nested_array'][0][1]);
        $this->assertSame('b', $data['nested_array'][1][1]);
        $this->assertFalse($data['nested_array'][2][1]);

        $this->assertIsArray($data['empty_array']);
        $this->assertCount(0, $data['empty_array']);
    }

    public function testYAMLObject(): void
    {
        $yaml = <<<YAML
          simple_object:
            name: John Doe
            age: 30
            active: true
          nested_object:
            user:
              profile:
                name: Jane
                settings:
                  theme: dark
                  notifications: false
                  timeout: 300
          empty_object: {}
        YAML;

        $data = $this->parser->parse($yaml);

        $this->assertSame('John Doe', $data['simple_object']['name']);
        $this->assertSame(30, $data['simple_object']['age']);
        $this->assertTrue($data['simple_object']['active']);

        $this->assertArrayHasKey('user', $data['nested_object']);
        $this->assertArrayHasKey('profile', $data['nested_object']['user']);
        $this->assertArrayHasKey(
            'settings',
            $data['nested_object']['user']['profile'],
        );

        $this->assertSame(
            'Jane',
            $data['nested_object']['user']['profile']['name'],
        );
        $this->assertSame(
            'dark',
            $data['nested_object']['user']['profile']['settings']['theme'],
        );

        $this->assertIsArray($data['empty_object']);
        $this->assertCount(0, $data['empty_object']);
    }

    public function testYAMLParseExceptionInvalid(): void
    {
        $this->expectException(Parse::class);
        $this->parser->parse(':value');
    }

    public function testYAMLParseExceptionNumber(): void
    {
        $this->expectException(Parse::class);
        $this->parser->parse(12);
    }

    public function testYAMLParseExceptionBoolean(): void
    {
        $this->expectException(Parse::class);
        $this->parser->parse(false);
    }

    public function testYAMLParseExceptionNull(): void
    {
        $this->expectException(Parse::class);
        $this->parser->parse(null);
    }

    public function testYAMLScalarTopLevelThrows(): void
    {
        // Valid YAML, but not a mapping — must raise Parse, not a TypeError.
        foreach (['123', '3.14', 'true', 'plain string'] as $scalar) {
            try {
                $this->parser->parse($scalar);
                $this->fail("Expected Parse for scalar YAML input: {$scalar}");
            } catch (Parse) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testYAMLTopLevelSequenceThrows(): void
    {
        // A non-empty sequence is not a config map.
        $this->expectException(Parse::class);
        $this->parser->parse("- a\n- b");
    }

    public function testYAMLEdgeCases(): void
    {
        $data = $this->parser->parse('');
        $this->assertCount(0, $data);
    }

    public function testYAMLComment(): void
    {
        $yaml = <<<YAML
          keyWithComment: value # A comment
          # Another comment, with empty line below intentionally
          
        YAML;

        $data = $this->parser->parse($yaml);

        $this->assertSame('value', $data['keyWithComment']);
        $this->assertCount(1, array_keys($data));
    }
}
