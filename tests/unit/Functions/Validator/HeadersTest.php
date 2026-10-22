<?php

declare(strict_types=1);

namespace Tests\Unit\Functions\Validator;

use Appwrite\Functions\Validator\Headers;
use PHPUnit\Framework\TestCase;

final class HeadersTest extends TestCase
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
        $this->assertTrue($this->object->isValid($headers));

        $headers = [
            'headerKey' => 'headerValue',
            'x-appwrite-key' => 'headerValue',
        ];
        $this->assertFalse($this->object->isValid($headers));

        $headers = [
            'headerKey' => 'headerValue',
            'headerKey2' => 'headerValue2',
        ];
        $this->assertTrue($this->object->isValid($headers));

        $headers = [
            'headerKey' => 'headerValue',
            'x-appwrite-project' => 'headerValue',
            'headerKey2' => 'headerValue2',
        ];
        $this->assertFalse($this->object->isValid($headers));

        $headers = [
            'headerKey' => 'headerValue',
            'X-Appwrite-User-Id' => 'impersonated-user',
        ];
        $this->assertFalse($this->object->isValid($headers));

        $headers = [
            'X-APPWRITE-TRIGGER' => 'event',
            'X-APPWRITE-EVENT' => 'users.user.update',
            'X-APPWRITE-KEY' => 'caller-supplied-key',
        ];
        $this->assertFalse($this->object->isValid($headers));

        $headers = [
            'x-AppWrite-User-Jwt' => 'token',
        ];
        $this->assertFalse($this->object->isValid($headers));

        $headers = [
            'header/////Key' => 'headerValue',
        ];
        $this->assertFalse($this->object->isValid($headers));

        // A line break in a value would start a second header line on the
        // request to the runtime, one the caller may not send directly.
        $headers = [
            'x-custom' => "value\r\nx-appwrite-user-id: impersonated-user",
        ];
        $this->assertFalse($this->object->isValid($headers));

        $headers = [
            'x-custom' => "value\nx-appwrite-user-id: impersonated-user",
        ];
        $this->assertFalse($this->object->isValid($headers));

        $headers = [
            'x-custom' => "value\0",
        ];
        $this->assertFalse($this->object->isValid($headers));

        $headers = [
            'x-custom' => "tab\tseparated value, with ümlauts",
        ];
        $this->assertTrue($this->object->isValid($headers));

        // The executor's protocol with the runtime is not the caller's to speak
        $headers = [
            'X-Open-Runtimes-Log-Id' => '../../other-runtime/logs/execution',
        ];
        $this->assertFalse($this->object->isValid($headers));

        $headers = [
            'x-open-runtimes-timeout' => '1',
        ];
        $this->assertFalse($this->object->isValid($headers));

        $headers = [
            'Content-Type' => 'application/json',
            'X-Custom-Header' => 'value'
        ];
        $this->assertTrue($this->object->isValid($headers));

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
            'X-Header' => null,
        ];
        $this->assertTrue($this->object->isValid($headers));

        $headers = [
            'X-Header' => ['bad'],
        ];
        $this->assertFalse($this->object->isValid($headers));

        $headers = [
            'X-Header' => 123,
        ];
        $this->assertTrue($this->object->isValid($headers));

        $headers = [
            'X-Header' => true,
        ];
        $this->assertTrue($this->object->isValid($headers));

        $headers = [
            'bad/name' => 'value',
        ];
        $this->assertFalse($this->object->isValid($headers));

        $headers = [
            true => 'value',
        ];
        $this->assertFalse($this->object->isValid($headers));

        $headers = [
            'a' => 'b',
        ];
        $this->assertTrue($this->object->isValid($headers));

        $headers = 123;
        $this->assertFalse($this->object->isValid($headers));

        $headers = true;
        $this->assertFalse($this->object->isValid($headers));

        $headers = 'string';
        $this->assertFalse($this->object->isValid($headers));

        $headers = [ ];
        $this->assertTrue($this->object->isValid($headers));

        $headers = [];
        for ($i = 0; $i < 100; $i++) {
            $headers['key-' . $i] = 'value_' . $i;
        }
        $this->assertTrue($this->object->isValid($headers));

        $headers['key-oversized'] = bin2hex(random_bytes(100000));
        $this->assertFalse($this->object->isValid($headers));

        unset($headers['key-oversized']);
        $this->assertTrue($this->object->isValid($headers));

        $headers['key-101'] = 'value_101';
        $this->assertFalse($this->object->isValid($headers));
    }

    public function testWithoutCookie(): void
    {
        $headers = [
            'Content-Type' => 'text/html',
            'Cookie' => 'a_jwt_console=preview-token; theme=dark; a_session_console=abc',
        ];

        $this->assertSame([
            'Content-Type' => 'text/html',
            'Cookie' => 'theme=dark; a_session_console=abc',
        ], Headers::withoutCookie($headers, 'a_jwt_console'));

        // The header goes when the cookie was all it carried
        $this->assertSame([
            'Content-Type' => 'text/html',
        ], Headers::withoutCookie([
            'Content-Type' => 'text/html',
            'cookie' => ' a_jwt_console=preview-token ',
        ], 'a_jwt_console'));

        // Other cookies, including ones whose name merely starts the same, stay
        $this->assertSame([
            'cookie' => 'a_jwt_console_theme=dark; x=1',
        ], Headers::withoutCookie([
            'cookie' => 'a_jwt_console_theme=dark; x=1',
        ], 'a_jwt_console'));

        $this->assertSame([], Headers::withoutCookie([], 'a_jwt_console'));
    }

    public function testStripReserved(): void
    {
        $headers = [
            'Content-Type' => 'application/json',
            'X-Custom-Header' => 'ok',
            'x-appwrite-user-id' => 'spoofed',
            'X-Appwrite-Trigger' => 'event',
            'X-APPWRITE-KEY' => 'caller-supplied-key',
            'x-AppWrite-Event' => 'users.user.update',
        ];

        $this->assertSame([
            'Content-Type' => 'application/json',
            'X-Custom-Header' => 'ok',
        ], Headers::stripReserved($headers));

        $this->assertSame([], Headers::stripReserved([
            'x-appwrite-user-id' => '',
        ]));

        $this->assertSame([
            'x-custom' => 'ok',
        ], Headers::stripReserved([
            'x-custom' => 'ok',
            'X-Open-Runtimes-Log-Id' => '../../other-runtime/logs/execution',
            'x-open-runtimes-secret' => 'guess',
        ]));

        $this->assertSame([
            'Content-Type' => 'application/json',
            'x-appwrite-client-ip' => '203.0.113.10',
        ], Headers::stripReserved([
            'Content-Type' => 'application/json',
            'X-Appwrite-Client-Ip' => '198.51.100.1',
            'x-appwrite-client-ip' => '203.0.113.10',
            'x-appwrite-user-id' => 'caller',
        ], ['x-appwrite-client-ip']));
    }
}
