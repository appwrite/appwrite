<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Validator;

use Appwrite\Utopia\Database\Validator\KeywordId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KeywordIdTest extends TestCase
{
    protected ?KeywordId $object = null;

    public function setUp(): void
    {
        $this->object = new KeywordId('current()');
    }

    public static function provideTest(): \Iterator
    {
        yield 'the keyword' => ['current()', true];
        yield 'plain id' => ['abc123', true];
        yield 'bare keyword' => ['current', true];
        yield 'uppercase' => ['ABC', true];
        yield 'underscore' => ['under_score', true];
        yield 'hyphen' => ['as12-df34', true];
        yield 'period' => ['as12.df34', true];
        yield '36 chars' => [\str_repeat('a', 36), true];
        yield 'another validator\'s keyword' => ['recent()', false];
        yield 'unique()' => ['unique()', false];
        yield 'uppercase keyword' => ['CURRENT()', false];
        yield 'keyword with spaces' => [' current() ', false];
        yield 'unclosed keyword' => ['current(', false];
        yield 'percent encoded' => ['current%28%29', false];
        yield 'leading underscore' => ['_current', false];
        yield 'leading dash' => ['-dash', false];
        yield 'empty' => ['', false];
        yield 'too long' => [\str_repeat('a', 37), false];
    }

    #[DataProvider('provideTest')]
    public function testValues(string $input, bool $expected): void
    {
        $this->assertSame($expected, $this->object->isValid($input));
    }

    /**
     * Each instance accepts only the keyword it was built with; a bare word is
     * an ordinary ID either way.
     */
    public function testKeywordIsScopedToInstance(): void
    {
        $recent = new KeywordId('recent()');

        $this->assertTrue($recent->isValid('recent()'));
        $this->assertFalse($recent->isValid('current()'));
        $this->assertTrue($recent->isValid('recent'));
    }

    public function testCustomMaxLength(): void
    {
        $validator = new KeywordId('current()', 255);

        $this->assertTrue($validator->isValid(\str_repeat('a', 255)));
        $this->assertFalse($validator->isValid(\str_repeat('a', 256)));

        // The keyword is exempt from length rules by construction.
        $this->assertTrue((new KeywordId('current()', 3))->isValid('current()'));
    }

    public function testDescriptionMentionsKeyword(): void
    {
        $this->assertStringContainsString('current()', $this->object->getDescription());
        $this->assertStringContainsString('recent()', (new KeywordId('recent()'))->getDescription());
    }
}
