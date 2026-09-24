<?php

declare(strict_types=1);

namespace Utopia\Validator;

use PHPUnit\Framework\TestCase;
use Utopia\Validator;

final class MultipleOfTest extends TestCase
{
    public function setUp(): void {}

    public function testIsValid(): void
    {
        $validator = new AllOf([new Text(20), new URL()], Validator::TYPE_STRING);

        $this->assertSame('string', $validator->getType());
        $this->assertSame('Value must be a valid string and at least 1 chars and no longer than 20 chars', $validator->getDescription());

        // Valid URL but invalid text length
        $this->assertFalse($validator->isValid('http://example.com/very-long-url'));

        // Valid text within length, but invalid URL
        $this->assertFalse($validator->isValid('hello world'));

        // Both conditions satisfied
        $this->assertTrue($validator->isValid('http://example.com'));
        $this->assertTrue($validator->isValid('https://google.com'));

        // Neither condition satisfied
        $this->assertFalse($validator->isValid('example.com/hello-world'));
        $this->assertFalse($validator->isValid(''));
    }

    public function testRules(): void
    {
        $validTextValidUrl = 'http://example.com';
        $validTextInvalidUrl = 'hello world';
        $invalidTextValidUrl = 'http://example.com/very-long-url';
        $invalidTextInvalidUrl = 'Some very long text that is also not an URL';

        $vaidator = new AllOf([new Text(20), new URL()], Validator::TYPE_STRING);
        $this->assertTrue($vaidator->isValid($validTextValidUrl));
        $this->assertFalse($vaidator->isValid($validTextInvalidUrl));
        $this->assertFalse($vaidator->isValid($invalidTextValidUrl));
        $this->assertFalse($vaidator->isValid($invalidTextInvalidUrl));

        $vaidator = new AnyOf([new Text(20), new URL()], Validator::TYPE_STRING);
        $this->assertTrue($vaidator->isValid($validTextValidUrl));
        $this->assertTrue($vaidator->isValid($validTextInvalidUrl));
        $this->assertTrue($vaidator->isValid($invalidTextValidUrl));
        $this->assertFalse($vaidator->isValid($invalidTextInvalidUrl));

        $this->assertCount(2, $vaidator->getValidators());
        $this->assertInstanceOf(\Utopia\Validator\Text::class, $vaidator->getValidators()[0]);
        $this->assertInstanceOf(\Utopia\Validator\URL::class, $vaidator->getValidators()[1]);

        $vaidator = new NoneOf([new Text(20), new URL()], Validator::TYPE_STRING);
        $this->assertFalse($vaidator->isValid($validTextValidUrl));
        $this->assertFalse($vaidator->isValid($validTextInvalidUrl));
        $this->assertFalse($vaidator->isValid($invalidTextValidUrl));
        $this->assertTrue($vaidator->isValid($invalidTextInvalidUrl));
    }
}
