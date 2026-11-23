<?php

namespace Utopia\Abuse\Challenges;

/**
 * Derives the identity tuple that both the challenge token and the clearance
 * token are bound to. Keeping the derivation in one place guarantees issue-time
 * and verify-time compute the exact same values.
 *
 * The tuple is deliberately coarse: it can only make a *copied* credential fail
 * elsewhere (subtractive check), never grant passage to a client that holds no
 * credential. IP prefix + UA hash are soft, best-effort bindings.
 */
final class Identity
{
    /**
     * Coarse client-IP prefix: /24 for IPv4, /48 for IPv6. Coarse on purpose —
     * a user's exact IP can drift between fetching the challenge and solving it
     * (CGNAT, WiFi<->cellular); binding to a prefix tolerates that drift while
     * still defeating cross-network credential sharing.
     */
    public static function ipPrefix(string $ip): string
    {
        $ip = \trim($ip);
        if ($ip === '') {
            return '';
        }

        $binary = @\inet_pton($ip);
        if ($binary === false) {
            // Unparseable (e.g. malformed proxy header) — bind to the raw value
            // rather than silently collapsing everyone to an empty prefix.
            return $ip;
        }

        if (\strlen($binary) === 4) {
            // IPv4: keep the first 3 octets, zero the last → a.b.c.0/24
            $prefix = \inet_ntop(\substr($binary, 0, 3) . "\0");

            return ($prefix === false ? $ip : $prefix) . '/24';
        }

        // IPv6: keep the first 6 bytes (48 bits), zero the remaining 10 → .../48
        $prefix = \inet_ntop(\substr($binary, 0, 6) . \str_repeat("\0", 10));

        return ($prefix === false ? $ip : $prefix) . '/48';
    }

    /**
     * Hash of the User-Agent. Bound into the credentials so a token minted for
     * one UA cannot be replayed under a different one.
     */
    public static function uaHash(string $userAgent): string
    {
        return \hash('sha256', $userAgent);
    }
}
