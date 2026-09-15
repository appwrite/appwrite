<?php

declare(strict_types=1);

namespace Tests\Unit\GraphQL\Types;

use Appwrite\GraphQL\Types\Assoc;
use Appwrite\GraphQL\Types\Json;
use GraphQL\Language\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JsonTest extends TestCase
{
    #[DataProvider('literals')]
    public function testParseLiteral(string $literal, mixed $expected, array $variables = []): void
    {
        $node = Parser::parseValue($literal);

        $this->assertSame($expected, (new Json())->parseLiteral($node, $variables));
        $this->assertSame($expected, (new Assoc())->parseLiteral($node, $variables));
    }

    public static function literals(): \Iterator
    {
        yield 'integer' => ['42', 42];
        yield 'large integer' => ['9007199254740993', 9007199254740993];
        yield 'negative integer' => ['-9007199254740993', -9007199254740993];
        yield 'maximum integer' => [(string) PHP_INT_MAX, PHP_INT_MAX];
        yield 'minimum integer' => [(string) PHP_INT_MIN, PHP_INT_MIN];
        yield 'positive overflow matches JSON' => ['18446744073709551616', \json_decode('18446744073709551616')];
        yield 'negative overflow matches JSON' => ['-18446744073709551616', \json_decode('-18446744073709551616')];
        yield 'nested overflow matches JSON' => ['{values: [18446744073709551616]}', \json_decode('{"values": [18446744073709551616]}', true)];
        yield 'float' => ['1.25', 1.25];
        yield 'boolean' => ['false', false];
        yield 'null' => ['null', null];
        yield 'empty list' => ['[]', []];
        yield 'mixed list' => ['[0, false, null, "text", 1.25]', [0, false, null, 'text', 1.25]];
        yield 'nested object' => ['{name: "text", values: [1, {enabled: true}]}', [
            'name' => 'text',
            'values' => [1, ['enabled' => true]],
        ]];
        yield 'nested variables' => ['{name: $name, values: [$count, {value: $name}, $optional]}', [
            'name' => 'actual',
            'values' => [0, ['value' => 'actual'], null],
        ], ['name' => 'actual', 'count' => 0, 'optional' => null]];
    }

    public function testParseStringLiteral(): void
    {
        $this->assertSame('text', (new Json())->parseLiteral(Parser::parseValue('"text"')));
        $this->assertSame(['name' => 'text'], (new Assoc())->parseLiteral(Parser::parseValue('"{\\"name\\":\\"text\\"}"')));
    }
}
