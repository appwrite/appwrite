<?php

namespace Utopia\Locale;

use Exception;

class Locale
{
    const string DEFAULT_DYNAMIC_KEY = '[[defaultDynamicKey]]'; // Replaced at runime by $key wrapped in {{ and }}

    /**
     * @var array<string, array<string, string>>
     */
    protected static $language = [];

    /**
     * Throw Exceptions?
     *
     * @var bool
     */
    public static $exceptions = true;

    /**
     * Default Locale
     *
     * @var string
     */
    public $default;

    /**
     * Fallback locale. Used when specific or default locale is missing translation.
     * Should always be set to locale that includes all translations.
     *
     * @var string|null
     */
    public $fallback = null;

    /**
     * Get list of configured languages
     *
     * @return array<string>
     */
    public static function getLanguages(): array
    {
        return \array_keys(self::$language);
    }

    /**
     * Set New Locale from an array
     *
     * @param  string  $name
     * @param  array<string, string>  $translations
     */
    public static function setLanguageFromArray(string $name, array $translations): void //TODO add support for lazy load to memory
    {
        self::$language[$name] = $translations;
    }

    /**
     * Set New Locale from JSON file
     *
     * @param  string  $name
     * @param  string  $path
     */
    public static function setLanguageFromJSON(string $name, string $path): void
    {
        if (! file_exists($path) && self::$exceptions) {
            throw new Exception('Translation file not found.');
        }

        /** @var array<string, string> $translations */
        $translations = json_decode(file_get_contents($path) ?: '', true);
        self::$language[$name] = $translations;
    }

    public function __construct(string $default)
    {
        if (! \array_key_exists($default, self::$language) && self::$exceptions) {
            throw new Exception('Locale not found');
        }

        $this->default = $default;
    }

    /**
     * Change fallback Locale
     *
     * @param $name
     *
     * @throws Exception
     */
    public function setFallback(string $name): self
    {
        if (! \array_key_exists($name, self::$language) && self::$exceptions) {
            throw new Exception('Locale not found');
        }

        $this->fallback = $name;

        return $this;
    }

    /**
     * Change Default Locale
     *
     * @param $name
     *
     * @throws Exception
     */
    public function setDefault(string $name): self
    {
        if (! \array_key_exists($name, self::$language) && self::$exceptions) {
            throw new Exception('Locale not found');
        }

        $this->default = $name;

        return $this;
    }

    /**
     * Get Text by Locale
     *
     * @param  string  $key
     * @param  array<string, string|int>  $placeholders
     * @param  string|null  $default
     * @return mixed
     *
     * @throws Exception
     */
    public function getText(string $key, string|null $default = self::DEFAULT_DYNAMIC_KEY, array $placeholders = [])
    {
        $defaultExists = \array_key_exists($key, self::$language[$this->default]);
        $fallbackExists = \array_key_exists($key, self::$language[$this->fallback ?? ''] ?? []);

        $translation = $default === self::DEFAULT_DYNAMIC_KEY ? '{{'.$key.'}}' : $default;

        if ($fallbackExists) {
            $translation = self::$language[$this->fallback ?? ''][$key];
        }

        if ($defaultExists) {
            $translation = self::$language[$this->default][$key];
        }

        if (! $defaultExists && ! $fallbackExists && self::$exceptions) {
            throw new Exception('Key named "'.$key.'" not found');
        }

        if (\is_null($translation)) {
            return null;
        }

        foreach ($placeholders as $placeholderKey => $placeholderValue) {
            $translation = str_replace('{{'.$placeholderKey.'}}', (string) $placeholderValue, $translation);
        }

        return $translation;
    }

    /**
     * Get list of configured transltions in specific language
     *
     * @return array<string, string>
     */
    public function getTranslations(): array
    {
        return self::$language[$this->default];
    }
}
