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
