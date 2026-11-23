<?php

declare(strict_types=1);

namespace Utopia\Distribution\Tests\Signing;

/**
 * A PKCS#12 archive whose certificate bag claims pbeWithSHAAnd40BitRC2-CBC, the cipher `openssl pkcs12 -export -legacy`
 * uses, under a valid SHA-1 MAC. Without the legacy provider OpenSSL rejects it at the cipher fetch, as it does real
 * legacy archives; the encrypted content is random because it is never decrypted.
 */
final class Legacy
{
    public const string RC2 = 'rc2-40-cbc';

    private const string DATA = '1.2.840.113549.1.7.1';

    private const string ENCRYPTED = '1.2.840.113549.1.7.6';

    private const string RC2_40 = '1.2.840.113549.1.12.1.6';

    private const string SHA1 = '1.3.14.3.2.26';

    private const int MAC_ID = 3;

    private const int BLOCK = 64;

    private const int ITERATIONS = 2048;

    public static function pkcs12(string $password): string
    {
        $cipher = self::sequence(self::oid(self::RC2_40), self::sequence(self::octets(random_bytes(8)), self::integer(self::ITERATIONS)));
        $encrypted = self::sequence(
            self::integer(0),
            self::sequence(self::oid(self::DATA), $cipher, self::tag(0x80, random_bytes(1024))),
        );
        $safe = self::sequence(self::sequence(self::oid(self::ENCRYPTED), self::tag(0xA0, $encrypted)));

        $salt = random_bytes(8);
        $mac = hash_hmac('sha1', $safe, self::key($password, $salt), true);

        return self::sequence(
            self::integer(3),
            self::sequence(self::oid(self::DATA), self::tag(0xA0, self::octets($safe))),
            self::sequence(
                self::sequence(self::sequence(self::oid(self::SHA1), "\x05\x00"), self::octets($mac)),
                self::octets($salt),
                self::integer(1),
            ),
        );
    }

    private static function key(string $password, string $salt): string
    {
        $bmp = '';
        foreach (str_split($password) as $character) {
            $bmp .= "\x00{$character}";
        }

        return sha1(str_repeat(pack('C', self::MAC_ID), self::BLOCK) . self::fill($salt) . self::fill($bmp . "\x00\x00"), true);
    }

    private static function fill(string $value): string
    {
        $length = self::BLOCK * (int) ceil(\strlen($value) / self::BLOCK);

        return substr(str_repeat($value, (int) ceil($length / \strlen($value))), 0, $length);
    }

    private static function sequence(string ...$items): string
    {
        return self::tag(0x30, implode('', $items));
    }

    private static function octets(string $value): string
    {
        return self::tag(0x04, $value);
    }

    private static function integer(int $value): string
    {
        $bytes = ltrim(pack('N', $value), "\x00");
        if ($bytes === '' || (\ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }

        return self::tag(0x02, $bytes);
    }

    private static function oid(string $dotted): string
    {
        $arcs = array_map(intval(...), explode('.', $dotted));
        $bytes = pack('C', 40 * $arcs[0] + $arcs[1]);
        foreach (\array_slice($arcs, 2) as $arc) {
            $encoded = pack('C', $arc & 0x7F);
            while (($arc >>= 7) > 0) {
                $encoded = pack('C', 0x80 | ($arc & 0x7F)) . $encoded;
            }

            $bytes .= $encoded;
        }

        return self::tag(0x06, $bytes);
    }

    private static function tag(int $tag, string $value): string
    {
        $length = \strlen($value);
        if ($length < 0x80) {
            return pack('C', $tag) . pack('C', $length) . $value;
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return pack('C', $tag) . pack('C', 0x80 | \strlen($bytes)) . $bytes . $value;
    }
}
