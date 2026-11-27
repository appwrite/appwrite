<?php

namespace Utopia\Abuse\Challenge;

/**
 * The coarse client identity challenge tokens are bound to: an IP prefix and a
 * User-Agent hash. Coarse on purpose — an IP can drift between issue and verify
 * (CGNAT, Wi-Fi to cellular), and a prefix tolerates that while still making a
 * credential copied to another network fail.
 */
final readonly class Identity
{
    /**
     * @param  int  $ipv4Bits  IPv4 prefix length kept, 0–32
     * @param  int  $ipv6Bits  IPv6 prefix length kept, 0–128
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        private int $ipv4Bits = 24,
        private int $ipv6Bits = 48,
    ) {
        if ($ipv4Bits < 0 || $ipv4Bits > 32) {
            throw new \InvalidArgumentException('ipv4Bits must be between 0 and 32');
        }
        if ($ipv6Bits < 0 || $ipv6Bits > 128) {
            throw new \InvalidArgumentException('ipv6Bits must be between 0 and 128');
        }
    }

    /**
     * The network the IP belongs to in CIDR form, e.g. "203.0.113.0/24". An
     * unparseable value is returned as given, so malformed input does not
     * collapse everyone into one empty prefix.
     */
    public function ipPrefix(string $ip): string
    {
        $ip = \trim($ip);
        if ($ip === '') {
            return '';
        }

        $binary = @\inet_pton($ip);
        if ($binary === false) {
            return $ip;
        }

        $bits = \strlen($binary) === 4 ? $this->ipv4Bits : $this->ipv6Bits;
        $prefix = \inet_ntop(self::mask($binary, $bits));

        return ($prefix === false ? $ip : $prefix) . '/' . $bits;
    }

    /**
     * Hash of the User-Agent, so a credential minted for one UA fails under another.
     */
    public function userAgentHash(string $userAgent): string
    {
        return \hash('sha256', $userAgent);
    }

    /**
     * Keep the first $bits bits of the address and zero the rest.
     */
    private static function mask(string $binary, int $bits): string
    {
        $masked = '';
        $length = \strlen($binary);
        for ($i = 0; $i < $length; $i++) {
            $keep = \max(0, \min(8, $bits - $i * 8));
            $masked .= \chr(\ord($binary[$i]) & (0xFF << (8 - $keep)) & 0xFF);
        }

        return $masked;
    }
}
