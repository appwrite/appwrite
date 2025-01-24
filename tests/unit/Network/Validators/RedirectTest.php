<?php

namespace Tests\Unit\Network\Validators;

use Appwrite\Network\Validator\Redirect;
use PHPUnit\Framework\TestCase;

class RedirectTest extends TestCase
{
    public function redirectsProvider(): array
    {
        return [
            "expo scheme" => [[], ["exp"], "exp://192.168.0.1", true],
            "custom scheme" => [[], ["myapp"], "myapp://", true],
            "custom scheme triple slash" => [[], ["myapp"], "myapp:///", true],
            "scheme with special chars" => [[], "my-app+custom.123://", true],
            "url https" => [["example.com"], [], "https://example.com", true],
            "url http" => [["example.com"], [], "http://example.com", true],
            "malformed scheme" => [[], "http:/example.com", false],
            "invalid url" => [[], [], "example.com", false],
            "invalid host" => [["notexample.com"], [], "https://example.com", false],
            "javascript scheme" => [[], "javascript://alert(1)", false],
            "javascript scheme with different case" => [[], "JaVaScRiPt://alert(1)", false],
            "empty string" => [[], "", false],
        ];
    }

    /**
     * @dataProvider redirectsProvider
     */
    public function testIsValid(
        array $hostnames,
        array $schemes,
        string $value,
        bool $expected
    ): void {
        $validator = new Redirect($hostnames, $schemes);

        $this->assertEquals($expected, $validator->isValid($value));
    }
}
