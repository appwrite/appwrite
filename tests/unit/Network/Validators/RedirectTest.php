<?php

namespace Tests\Unit\Network\Validators;

use Appwrite\Network\Validator\Redirect;
use PHPUnit\Framework\TestCase;

class RedirectTest extends TestCase
{
    public function redirectsProvider(): array
    {
        return [
            "custom scheme" => [[], "exp://192.168.0.1", true],
            "only scheme with triple slash" => [[], "myapp:///", true],
            "only scheme" => [[], "myapp://", true],
            "javascript scheme" => [[], "javascript://alert(1)", false],
            "invalid url" => [[], "192.168.0.1", false],
            "scheme case + invalid host" => [
                ["notexample.com"],
                "HTTPS://example.com",
                false,
            ],
            "scheme case + valid host" => [
                ["example.com"],
                "HTTPS://example.com",
                true,
            ],
            "javascript scheme with different case" => [[], "JaVaScRiPt://alert(1)", false],
            "multiple slashes after scheme" => [[], "myapp:////", true],
            "scheme with special chars" => [[], "my-app+custom.123://", true],
            "empty string" => [[], "", false],
            "malformed scheme" => [[], "http:/example.com", false],
        ];
    }

    /**
     * @dataProvider redirectsProvider
     */
    public function testIsValid(
        array $allowList,
        string $value,
        bool $expected
    ): void {
        $validator = new Redirect($allowList);

        $this->assertEquals($expected, $validator->isValid($value));
    }
}
