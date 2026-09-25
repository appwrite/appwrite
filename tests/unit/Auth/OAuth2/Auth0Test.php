<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Auth0;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Auth0Test extends TestCase
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
        $secret = ['clientSecret' => 'client-secret', 'auth0Domain' => 'example.us.auth0.com'];
        if ($prompt !== null) {
            $secret['prompt'] = $prompt;
        }

        $auth0 = new Auth0('client-id', \json_encode($secret), 'https://example.com/callback');

        \parse_str((string) \parse_url($auth0->getLoginURL(), PHP_URL_QUERY), $query);

        $this->assertSame($expected, $query['prompt'] ?? null);
    }
}
