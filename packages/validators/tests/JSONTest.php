<?php

declare(strict_types=1);

namespace Utopia\Validator\Tests;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Utopia\Validator\JSON;

#[RequiresPhpExtension('utopia')]
final class JSONTest extends TestCase
{
    public function testCanValidateJson(): void
    {
        $json = new JSON();
        $this->assertTrue($json->isValid('{}'));
        $this->assertTrue($json->isValid([]));
        $this->assertTrue($json->isValid(['test']));
        $this->assertTrue($json->isValid(['test' => 'demo']));
        $this->assertTrue($json->isValid('{"test": "demo"}'));

        $this->assertFalse($json->isValid(''));
        $this->assertFalse($json->isValid(false));
        $this->assertFalse($json->isValid(null));
        $this->assertFalse($json->isValid('string'));
        $this->assertFalse($json->isValid(1));
        $this->assertFalse($json->isValid(1.2));
        $this->assertFalse($json->isValid("{'test': 'demo'}"));
        $this->assertFalse($json->isArray());
        $this->assertSame(\Utopia\Validator\Validator::TYPE_OBJECT, $json->getType());
    }

    /**
     * `isValid()` leaves `json_last_error()` as `json_decode($value)` leaves it, whatever it was
     * before: the extension decodes without calling `json_decode()`.
     *
     * @return array<string, array{mixed, bool, int, string}>
     */
    public static function errorStates(): array
    {
        return [
            'object' => ['{"a":1}', true, JSON_ERROR_NONE, 'No error'],
            'empty string' => ['', false, JSON_ERROR_SYNTAX, 'Syntax error'],
            'syntax error' => ['{"a":', false, JSON_ERROR_SYNTAX, 'Syntax error'],
            'NUL property name' => ['{"\\u0000a":1}', false, JSON_ERROR_INVALID_PROPERTY_NAME, 'The decoded property name is invalid'],
            'invalid UTF-8' => ["[\"\xff\"]", false, JSON_ERROR_UTF8, 'Malformed UTF-8 characters, possibly incorrectly encoded'],
            '511 nested arrays' => [str_repeat('[', 511) . str_repeat(']', 511), true, JSON_ERROR_NONE, 'No error'],
            '512 nested arrays' => [str_repeat('[', 512) . str_repeat(']', 512), false, JSON_ERROR_DEPTH, 'Maximum stack depth exceeded'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('errorStates')]
    public function testLeavesJsonLastErrorAsJsonDecode(mixed $value, bool $valid, int $error, string $message): void
    {
        $json = new JSON();
        foreach (['{"stale":', '[]'] as $before) {
            json_decode($before);
            $this->assertSame($valid, $json->isValid($value));
            $this->assertSame($error, json_last_error());
            $this->assertSame($message, json_last_error_msg());
        }
    }

    public function testArraysAndOtherTypesLeaveJsonLastErrorAlone(): void
    {
        $json = new JSON();
        json_decode('{"stale":');
        $this->assertTrue($json->isValid(['a']));
        $this->assertFalse($json->isValid(1));
        $this->assertSame(JSON_ERROR_SYNTAX, json_last_error());
    }
}
