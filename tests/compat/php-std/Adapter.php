<?php

namespace Tests\Compat\PhpStd;

use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Session;

/**
 * PHP engine functions, called directly: the oracle for crates/support/php-std.
 * Operations live in ops/<area>.php, one file per area, each returning
 * `name => callable(array $args, Session $session): mixed`.
 */
final class Adapter implements Base, Benchmarks
{
    private const EMAIL = 'zoe.mueller+appwrite@mail.example.co.uk';

    private const EMAIL_PATTERN = '/^([a-z0-9._%+-]+)@([a-z0-9.-]+\.[a-z]{2,})$/i';

    private const NAME = 'Zoë MÜLLER-Łukasiewicz, ΑΘΗΝΑ Ελλάδα, Привет МИР, İSTANBUL Straße';

    private const URL = 'https://cloud.appwrite.io/v1/storage/buckets/default/files?project=demo&limit=25&cursor=65f1c0d2#top';

    /**
     * The engine functions the libraries lean on, the C implementation here
     * against php-std there.
     */
    public function benchmarks(): array
    {
        $document = fn (): string => (string) file_get_contents(__DIR__ . '/benchmarks/document.json');

        return [
            'json_encode' => ['json_encode() a 40-attribute user document', function (Session $s) use ($document) {
                $value = json_decode($document(), true);

                return fn () => json_encode($value);
            }],
            'json_decode' => ['json_decode() a 40-attribute user document into an array', function (Session $s) use ($document) {
                $json = $document();

                return fn () => json_decode($json, true);
            }],
            'unserialize' => ['unserialize() a 40-attribute user document', function (Session $s) use ($document) {
                $serialized = serialize(json_decode($document(), true));

                return fn () => unserialize($serialized);
            }],
            'igbinary_unserialize' => ['igbinary_unserialize() a 40-attribute user document', function (Session $s) use ($document) {
                $serialized = (string) igbinary_serialize(json_decode($document(), true));

                return fn () => igbinary_unserialize($serialized);
            }],
            'preg_match' => ['preg_match() an email address against a case-insensitive pattern with two groups', function (Session $s) {
                [$pattern, $email] = [self::EMAIL_PATTERN, self::EMAIL];

                return function () use ($pattern, $email) {
                    preg_match($pattern, $email, $matches);

                    return $matches;
                };
            }],
            'sprintf' => ['sprintf() a path with two strings, a padded integer and a float', function (Session $s) {
                [$format, $first, $second, $int, $float] = ['/v1/%s/%s/%05d?score=%.2f', 'databases', 'collections', 42, 99.5];

                return fn () => \sprintf($format, $first, $second, $int, $float);
            }],
            'mb_strtolower' => ['mb_strtolower() a 60-character multilingual name', function (Session $s) {
                $name = self::NAME;

                return fn () => mb_strtolower($name);
            }],
            'sort' => ['sort() a copy of 300 mixed ints, floats, numeric and other strings', function (Session $s) {
                $values = json_decode((string) file_get_contents(__DIR__ . '/benchmarks/values.json'), true);

                return function () use ($values) {
                    $copy = $values;
                    sort($copy);

                    return $copy;
                };
            }],
            'filter_email' => ['filter_var() an email address with FILTER_VALIDATE_EMAIL', function (Session $s) {
                $email = self::EMAIL;

                return fn () => filter_var($email, FILTER_VALIDATE_EMAIL);
            }],
            'filter_url' => ['filter_var() an API URL with a query and a fragment with FILTER_VALIDATE_URL', function (Session $s) {
                $url = self::URL;

                return fn () => filter_var($url, FILTER_VALIDATE_URL);
            }],
        ];
    }

    public function operations(): array
    {
        $operations = [];
        foreach (glob(__DIR__ . '/ops/*.php') ?: [] as $file) {
            $operations += require $file;
        }

        return $operations;
    }
}
