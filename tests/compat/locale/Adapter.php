<?php

namespace Tests\Compat\Locale;

use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Locale\Locale;

/**
 * Maps tests/compat/locale/spec.json operations onto utopia-php/locale. Glue only: no logic.
 *
 * Locale keeps its languages and exception mode in static state, which the
 * Rust crate models as a `Languages` value: `locale.reset` starts a fresh
 * one (every case begins with it).
 */
final class Adapter implements Base, Benchmarks
{
    public function benchmarks(): array
    {
        return [
            'new' => ['Create a request locale for German with an English fallback', function (Session $s) {
                self::languages();
                [$default, $fallback] = ['de', 'en'];

                return fn () => (new Locale($default))->setFallback($fallback);
            }],
            'text' => ['Translate a key of a 313-key language', function (Session $s) {
                $locale = self::languages('de');
                $key = 'countries.de';

                return fn () => $locale->getText($key);
            }],
            'placeholders' => ['Translate a session alert email line with six placeholders', function (Session $s) {
                $locale = self::languages('en');
                $key = 'emails.sessionAlert.body';
                $placeholders = [
                    'b' => '<strong>',
                    '/b' => '</strong>',
                    'project' => 'Appwrite Cloud',
                    'date' => 'November 2',
                    'year' => '2024',
                    'time' => '08:41:57',
                ];

                return fn () => $locale->getText($key, placeholders: $placeholders);
            }],
            'fallback' => ['Translate a key the default language lacks from the English fallback', function (Session $s) {
                $locale = self::languages('ar-ma');
                $key = 'emails.sessionAlert.subject';

                return fn () => $locale->getText($key);
            }],
        ];
    }

    /**
     * Appwrite's translations: English, German and Moroccan Arabic (which
     * lacks most email keys); with a default, a locale for it with an
     * English fallback.
     */
    private static function languages(?string $default = null): ?Locale
    {
        Languages::reset();
        foreach (['en', 'de', 'ar-ma'] as $name) {
            Locale::setLanguageFromJSON($name, __DIR__ . "/../../../app/config/locale/translations/{$name}.json");
        }

        return $default === null ? null : (new Locale($default))->setFallback('en');
    }

    public function operations(): array
    {
        return [
            'locale.reset' => function (array $a, Session $s) {
                Languages::reset();

                return;
            },
            'locale.exceptions' => function (array $a, Session $s) {
                Locale::$exceptions = $a['on'];

                return;
            },
            'languages.set_array' => function (array $a, Session $s) {
                Locale::setLanguageFromArray($a['name'], $a['translations']);

                return;
            },
            'languages.set_json' => function (array $a, Session $s) {
                Locale::setLanguageFromJSON($a['name'], $a['path']);

                return;
            },
            'languages.list' => fn (array $a, Session $s) => Locale::getLanguages(),
            'locale.new' => fn (array $a, Session $s) => $s->handle(new Locale($a['default'])),
            'locale.set_default' => function (array $a, Session $s) {
                self::locale($a, $s)->setDefault($a['name']);

                return;
            },
            'locale.set_fallback' => function (array $a, Session $s) {
                self::locale($a, $s)->setFallback($a['name']);

                return;
            },
            'locale.state' => fn (array $a, Session $s) => [
                'default' => self::locale($a, $s)->default,
                'fallback' => self::locale($a, $s)->fallback,
            ],
            'locale.text' => fn (array $a, Session $s) => self::locale($a, $s)->getText(
                $a['key'],
                ...self::text($a),
            ),
            'locale.translations' => fn (array $a, Session $s) => self::locale($a, $s)->getTranslations(),
            // Self-contained lookup for fuzzing: fresh languages, a locale, one getText().
            'locale.lookup' => function (array $a, Session $s) {
                Languages::reset();
                Locale::$exceptions = $a['exceptions'];
                foreach ($a['languages'] as $name => $translations) {
                    Locale::setLanguageFromArray((string) $name, $translations);
                }
                $locale = new Locale($a['language']);
                if (isset($a['fallback'])) {
                    $locale->setFallback($a['fallback']);
                }

                return $locale->getText($a['key'], ...self::text($a));
            },
        ];
    }

    /**
     * getText()'s optional arguments: `default` (absent: not passed) and `placeholders`.
     *
     * @return array<string, mixed>
     */
    private static function text(array $a): array
    {
        $args = [];
        if (\array_key_exists('default', $a)) {
            $args['default'] = $a['default'];
        }
        if (isset($a['placeholders'])) {
            $args['placeholders'] = $a['placeholders'];
        }

        return $args;
    }

    private static function locale(array $a, Session $s): Locale
    {
        $locale = $s->get($a['locale']);

        return $locale instanceof Locale ? $locale : throw new Fault('not a locale');
    }
}

/**
 * Access to Locale's static languages, to start each case from none.
 */
final class Languages extends Locale
{
    public static function reset(): void
    {
        self::$language = [];
        Locale::$exceptions = true;
    }
}
