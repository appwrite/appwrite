<?php

namespace Utopia\Locale;

use Exception;

class Locale
{
    public const string DEFAULT_DYNAMIC_KEY = '[[defaultDynamicKey]]'; // Replaced at runime by $key wrapped in {{ and }}

    /**
     * @var array<string, array<string, string>>
     */
    protected static $language = [];

    /**
     * Locale whose plural rules apply to each language
     *
     * @var array<string, string>
     */
    protected static $rules = [];

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
     * @param  string|null  $rules  Locale whose plural rules apply, defaults to $name
     */
    public static function setLanguageFromArray(string $name, array $translations, ?string $rules = null): void //TODO add support for lazy load to memory
    {
        self::$language[$name] = $translations;
        self::$rules[$name] = $rules ?? $name;
    }

    /**
     * Set New Locale from JSON file
     *
     * @param  string  $name
     * @param  string  $path
     * @param  string|null  $rules  Locale whose plural rules apply, defaults to $name
     */
    public static function setLanguageFromJSON(string $name, string $path, ?string $rules = null): void
    {
        if (! file_exists($path) && self::$exceptions) {
            throw new Exception('Translation file not found.');
        }

        /** @var array<string, string> $translations */
        $translations = json_decode(file_get_contents($path) ?: '', true);
        self::$language[$name] = $translations;
        self::$rules[$name] = $rules ?? $name;
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
     * Plural placeholders are formatted in the same language as the translation they fill.
     *
     * @param  string  $key
     * @param  string|null  $default
     * @param  array<string, string|int>  $placeholders
     * @param  array<string, array{string, int|float}>  $plurals  Placeholder name => [plural key, count]
     * @return mixed
     *
     * @throws Exception
     */
    public function getText(string $key, string|null $default = self::DEFAULT_DYNAMIC_KEY, array $placeholders = [], array $plurals = [])
    {
        $defaultExists = \array_key_exists($key, self::$language[$this->default]);
        $fallbackExists = \array_key_exists($key, self::$language[$this->fallback ?? ''] ?? []);

        $translation = $default === self::DEFAULT_DYNAMIC_KEY ? '{{'.$key.'}}' : $default;
        $language = $this->default;

        if ($fallbackExists) {
            $translation = self::$language[$this->fallback ?? ''][$key];
            $language = $this->fallback ?? '';
        }

        if ($defaultExists) {
            $translation = self::$language[$this->default][$key];
            $language = $this->default;
        }

        if (! $defaultExists && ! $fallbackExists && self::$exceptions) {
            throw new Exception('Key named "'.$key.'" not found');
        }

        if (\is_null($translation)) {
            return;
        }

        foreach ($plurals as $placeholderKey => [$pluralKey, $count]) {
            $placeholders[$placeholderKey] = $this->format($language, $pluralKey, $count) ?? '{{'.$pluralKey.'}}';
        }

        foreach ($placeholders as $placeholderKey => $placeholderValue) {
            $translation = str_replace('{{'.$placeholderKey.'}}', (string) $placeholderValue, $translation);
        }

        return $translation;
    }

    /**
     * Get plural text by Locale
     *
     * The translation is an ICU MessageFormat pattern with a `count` argument, for example
     * `{count, plural, one {# minute} other {# minutes}}`.
     *
     * @param  string  $key
     * @param  int|float  $count
     * @param  string|null  $default
     * @param  array<string, string|int|float>  $arguments  Other ICU arguments of the pattern, written as `{name}`
     *
     * @throws Exception
     */
    public function getPlural(string $key, int|float $count, string|null $default = self::DEFAULT_DYNAMIC_KEY, array $arguments = []): ?string
    {
        return $this->format($this->default, $key, $count, $arguments) ?? ($default === self::DEFAULT_DYNAMIC_KEY ? '{{'.$key.'}}' : $default);
    }

    /**
     * Format a plural translation with the rules of the language that has it, trying the fallback language next
     *
     * @param  string  $language
     * @param  string  $key
     * @param  int|float  $count
     * @param  array<string, string|int|float>  $arguments
     *
     * @throws Exception
     */
    protected function format(string $language, string $key, int|float $count, array $arguments = []): ?string
    {
        $invalid = null;

        foreach (\array_unique(\array_filter([$language, $this->fallback])) as $name) {
            $pattern = self::$language[$name][$key] ?? null;

            if (! \is_string($pattern)) {
                continue;
            }

            try {
                $formatter = new \MessageFormatter(self::$rules[$name] ?? $name, $pattern);
                $text = $formatter->format(['count' => $count] + $arguments);
                $error = $formatter->getErrorMessage();
            } catch (\IntlException $exception) {
                $text = false;
                $error = $exception->getMessage();
            }

            if (\is_string($text)) {
                return $text;
            }

            $invalid ??= 'Key named "'.$key.'" in "'.$name.'" could not be formatted: '.$error;
        }

        if (self::$exceptions) {
            throw new Exception($invalid ?? 'Key named "'.$key.'" not found');
        }

        return null;
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
