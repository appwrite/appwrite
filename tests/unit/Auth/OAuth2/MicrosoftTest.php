<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Microsoft;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MicrosoftTest extends TestCase
{
    /**
     * @return \Iterator<string, array{array<int, string>|null, string|null}>
     */
    public static function prompts(): \Iterator
    {
        yield 'no prompt' => [null, null];
        yield 'empty prompt' => [[], null];
        yield 'select account' => [['select_account'], 'select_account'];
    }

    /**
     * @param array<int, string>|null $prompt
     */
    #[DataProvider('prompts')]
    public function testLoginURLPrompt(?array $prompt, ?string $expected): void
    {
        $secret = ['clientSecret' => 'client-secret', 'tenantID' => 'common'];
        if ($prompt !== null) {
            $secret['prompt'] = $prompt;
        }

        $microsoft = new Microsoft('client-id', \json_encode($secret), 'https://example.com/callback');

        \parse_str((string) \parse_url($microsoft->getLoginURL(), PHP_URL_QUERY), $query);

        $this->assertSame($expected, $query['prompt'] ?? null);
    }
}
