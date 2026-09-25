<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Okta;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OktaTest extends TestCase
{
    /**
     * @return \Iterator<string, array{array<int, string>|null, string|null}>
     */
    public static function prompts(): \Iterator
    {
        yield 'no prompt' => [null, null];
        yield 'empty prompt' => [[], null];
        yield 'none' => [['none'], 'none'];
        yield 'login and consent' => [['login', 'consent'], 'login consent'];
    }

    /**
     * @param array<int, string>|null $prompt
     */
    #[DataProvider('prompts')]
    public function testLoginURLPrompt(?array $prompt, ?string $expected): void
    {
        $secret = ['clientSecret' => 'client-secret', 'oktaDomain' => 'trial-6400025.okta.com'];
        if ($prompt !== null) {
            $secret['prompt'] = $prompt;
        }

        $okta = new Okta('client-id', \json_encode($secret), 'https://example.com/callback');

        \parse_str((string) \parse_url($okta->getLoginURL(), PHP_URL_QUERY), $query);

        $this->assertSame($expected, $query['prompt'] ?? null);
    }
}
