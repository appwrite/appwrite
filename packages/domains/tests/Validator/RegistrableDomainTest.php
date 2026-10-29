<?php

declare(strict_types=1);

namespace Utopia\Domains\Tests\Validator;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Domains\Validator\RegistrableDomain;

final class RegistrableDomainTest extends TestCase
{
    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function values(): array
    {
        return [
            'apex' => ['example.com', true],
            'subdomain' => ['app.example.com', true],
            'under a multi-label suffix' => ['bbc.co.uk', true],
            'under a private suffix' => ['user.github.io', true],
            'hyphenated' => ['my-app.example.com', true],
            'tld' => ['com', false],
            'multi-label suffix' => ['co.uk', false],
            'private suffix' => ['github.io', false],
            'unknown suffix' => ['example.notatld', false],
            'single label' => ['localhost', false],
            'ipv4' => ['127.0.0.1', false],
            'ipv6' => ['::1', false],
            'uppercase' => ['Example.com', false],
            'underscore' => ['exa_mple.com', false],
            'leading hyphen' => ['-example.com', false],
            'trailing dot' => ['example.com.', false],
            'empty label' => ['app..example.com', false],
            'url' => ['https://example.com', false],
            'port' => ['example.com:443', false],
            'path' => ['example.com/login', false],
            'empty' => ['', false],
            'not a string' => [123, false],
        ];
    }

    #[DataProvider('values')]
    public function testIsValid(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, new RegistrableDomain()->isValid($value));
    }
}
