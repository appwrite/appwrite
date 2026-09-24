<?php

declare(strict_types=1);

namespace Utopia\Tests\Auth\OAuth2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Auth\Enums\Prompt;
use Utopia\Auth\OAuth2\InvalidPromptException;
use Utopia\Auth\OAuth2\Prompts;

final class PromptsTest extends TestCase
{
    public function testParsesEmptyPrompt(): void
    {
        $prompts = Prompts::fromString('');

        $this->assertSame([], $prompts->toArray());
        $this->assertSame('', $prompts->toString());
        $this->assertFalse($prompts->contains(Prompt::Consent));
    }

    public function testParsesValidPromptValues(): void
    {
        $prompts = Prompts::fromString('login consent select_account consent');

        $this->assertSame(['login', 'consent', 'select_account'], $prompts->toArray());
        $this->assertSame('login consent select_account', $prompts->toString());
        $this->assertTrue($prompts->contains(Prompt::Login));
        $this->assertTrue($prompts->contains(Prompt::Consent));
        $this->assertTrue($prompts->contains(Prompt::SelectAccount));
        $this->assertFalse($prompts->contains(Prompt::None));
    }

    /**
     * @param non-empty-string $prompt
     */
    #[DataProvider('invalidPromptProvider')]
    public function testRejectsInvalidPromptValues(string $prompt, string $message): void
    {
        $this->assertSame('invalid_request', InvalidPromptException::ERROR_CODE);

        $this->expectException(InvalidPromptException::class);
        $this->expectExceptionMessage($message);

        Prompts::fromString($prompt);
    }

    /**
     * @return \Iterator<string, array{prompt: non-empty-string, message: string}>
     */
    public static function invalidPromptProvider(): \Iterator
    {
        yield 'unknown value' => [
            'prompt' => 'login unknown',
            'message' => "Invalid prompt value 'unknown'.",
        ];
        yield 'none combined' => [
            'prompt' => 'none consent',
            'message' => 'prompt=none cannot be combined with other prompt values.',
        ];
    }
}
