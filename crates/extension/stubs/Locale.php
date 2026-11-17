<?php

// Utopia\Locale\Locale is registered by the utopia PHP extension (crates/extension). This file
// only declares it for static analysis and editors; it is never loaded.

namespace Utopia\Locale;

/**
 * Translations by language, registered once per worker and read per request.
 */
class Locale
{
    /** Replaced in getText()'s result by the missing key wrapped in {{ and }}. */
    public const string DEFAULT_DYNAMIC_KEY = '[[defaultDynamicKey]]';

    /** Whether a missing language or key throws. */
    public static $exceptions = true;

    /** @var string */
    public $default;

    /**
     * Used when the default language misses a translation.
     *
     * @var string|null
     */
    public $fallback = null;

    /**
     * @return array<string>
     */
    public static function getLanguages(): array
    {
    }

    /**
     * @param  array<string, string>  $translations
     */
    public static function setLanguageFromArray(string $name, array $translations): void
    {
    }

    public static function setLanguageFromJSON(string $name, string $path): void
    {
    }

    /**
     * @throws \Exception When the language is not registered and exceptions are on
     */
    public function __construct(string $default)
    {
    }

    public function setFallback(string $name): self
    {
    }

    public function setDefault(string $name): self
    {
    }

    /**
     * @param  array<string, string|int>  $placeholders
     * @return mixed
     *
     * @throws \Exception When the key is missing and exceptions are on
     */
    public function getText(string $key, string|null $default = self::DEFAULT_DYNAMIC_KEY, array $placeholders = [])
    {
    }

    /**
     * @return array<string, string>
     */
    public function getTranslations(): array
    {
    }
}
