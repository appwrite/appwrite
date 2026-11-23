<?php

namespace Utopia\Emails\Canonicals\Providers;

use Utopia\Emails\Canonicals\Provider;

/**
 * Yandex
 *
 * Handles Yandex email normalization based on validator.js rules
 * - Preserves all characters in local part (no subaddress removal)
 * - Normalizes to yandex.ru domain
 */
class Yandex extends Provider
{
    private const SUPPORTED_DOMAINS = [
        'yandex.ru', 'yandex.ua', 'yandex.kz', 'yandex.com', 'yandex.by', 'ya.ru',
    ];

    private const CANONICAL_DOMAIN = 'yandex.ru';

    public function supports(string $domain): bool
    {
        return in_array($domain, self::SUPPORTED_DOMAINS, true);
    }

    public function getCanonical(string $local, string $domain): array
    {
        // Convert to lowercase
        $normalizedLocal = $this->toLowerCase($local);

        // Yandex doesn't remove subaddresses or dots
        // Just normalize case and domain

        return [
            'local' => $normalizedLocal,
            'domain' => self::CANONICAL_DOMAIN,
        ];
    }

    public function getCanonicalDomain(): string
    {
        return self::CANONICAL_DOMAIN;
    }

    public function getSupportedDomains(): array
    {
        return self::SUPPORTED_DOMAINS;
    }
}
