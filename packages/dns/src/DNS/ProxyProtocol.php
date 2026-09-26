<?php

declare(strict_types=1);

namespace Utopia\DNS;

use Exception;

/**
 * A parsed PROXY protocol header (v1 text or v2 binary), as prepended by
 * load balancers to preserve the original client address across TCP proxies.
 *
 * See https://www.haproxy.org/download/1.8/doc/proxy-protocol.txt
 */
final readonly class ProxyProtocol
{
    public const string SIGNATURE_V2 = "\r\n\r\n\x00\r\nQUIT\n";

    protected const string PREFIX_V1 = 'PROXY ';

    /**
     * Maximum v1 header length including CRLF per the specification.
     */
    protected const int MAX_V1_LENGTH = 107;

    /**
     * @param int $length Header size in bytes, to strip from the stream
     * @param string|null $ip Original client IP, or null when the header carries no address (LOCAL, UNKNOWN, UNSPEC)
     * @param int|null $port Original client port, or null when the header carries no address
     */
    public function __construct(
        public int $length,
        public ?string $ip,
        public ?int $port,
    ) {}

    /**
     * Parse a PROXY protocol header from the start of $buffer.
     *
     * Returns null when the buffer is a valid but incomplete header prefix
     * and more bytes are needed.
     *
     * @throws Exception When the buffer does not start with a valid header
     */
    public static function parse(string $buffer): ?self
    {
        if (str_starts_with($buffer, self::SIGNATURE_V2)) {
            return self::parseV2($buffer);
        }

        if (str_starts_with($buffer, self::PREFIX_V1)) {
            return self::parseV1($buffer);
        }

        if ($buffer === '' || str_starts_with(self::SIGNATURE_V2, $buffer) || str_starts_with(self::PREFIX_V1, $buffer)) {
            return null;
        }

        throw new Exception('Invalid PROXY protocol header.');
    }

    protected static function parseV1(string $buffer): ?self
    {
        $end = strpos($buffer, "\r\n");

        if ($end === false) {
            if (\strlen($buffer) >= self::MAX_V1_LENGTH) {
                throw new Exception('PROXY protocol v1 header is not terminated.');
            }

            return null;
        }

        $length = $end + 2;
        if ($length > self::MAX_V1_LENGTH) {
            throw new Exception('PROXY protocol v1 header exceeds the maximum length.');
        }

        $parts = explode(' ', substr($buffer, 0, $end));
        $protocol = $parts[1] ?? '';

        if ($protocol === 'UNKNOWN') {
            return new self($length, null, null);
        }

        if (!\in_array($protocol, ['TCP4', 'TCP6'], true) || \count($parts) !== 6) {
            throw new Exception('Invalid PROXY protocol v1 header.');
        }

        $flag = $protocol === 'TCP4' ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6;
        $ip = filter_var($parts[2], FILTER_VALIDATE_IP, $flag);
        $port = filter_var($parts[4], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 65535]]);

        if ($ip === false || $port === false) {
            throw new Exception('Invalid PROXY protocol v1 source address.');
        }

        return new self($length, $ip, $port);
    }

    protected static function parseV2(string $buffer): ?self
    {
        if (\strlen($buffer) < 16) {
            return null;
        }

        $versionCommand = \ord($buffer[12]);
        $command = $versionCommand & 0x0F;

        if (($versionCommand >> 4) !== 2 || $command > 1) {
            throw new Exception('Invalid PROXY protocol v2 header.');
        }

        $unpacked = unpack('n', substr($buffer, 14, 2));
        if (!\is_array($unpacked) || !\is_int($unpacked[1])) {
            throw new Exception('Invalid PROXY protocol v2 header.');
        }

        $length = 16 + $unpacked[1];
        if (\strlen($buffer) < $length) {
            return null;
        }

        // Command 0 is LOCAL (health checks): no address to carry over
        if ($command === 0) {
            return new self($length, null, null);
        }

        $addressSize = match (\ord($buffer[13]) >> 4) {
            1 => 4,  // AF_INET: src(4) dst(4) src_port(2) dst_port(2)
            2 => 16, // AF_INET6: src(16) dst(16) src_port(2) dst_port(2)
            default => null, // AF_UNSPEC / AF_UNIX: no usable address
        };

        if ($addressSize === null) {
            return new self($length, null, null);
        }

        if ($unpacked[1] < ($addressSize * 2) + 4) {
            throw new Exception('PROXY protocol v2 header is missing addresses.');
        }

        $ip = inet_ntop(substr($buffer, 16, $addressSize));
        $portUnpacked = unpack('n', substr($buffer, 16 + ($addressSize * 2), 2));

        if ($ip === false || !\is_array($portUnpacked) || !\is_int($portUnpacked[1])) {
            throw new Exception('Invalid PROXY protocol v2 source address.');
        }

        return new self($length, $ip, $portUnpacked[1]);
    }
}
