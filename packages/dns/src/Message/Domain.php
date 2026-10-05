<?php

declare(strict_types=1);

namespace Utopia\DNS\Message;

use Utopia\DNS\Exception\Message\DecodingException;

final readonly class Domain
{
    public const int MAX_LABEL_LEN = 63;
    public const int MAX_LABELS = 127;
    public const int MAX_DOMAIN_NAME_LEN = 255;

    /**
     * Encode a domain name according to RFC 1035.
     */
    public static function encode(string $name): string
    {
        if ($name === '') {
            return "\x00";
        }

        if (str_ends_with($name, '..')) {
            throw new \InvalidArgumentException('Domain labels must not be empty');
        }

        $trimmed = rtrim($name, '.');
        if ($trimmed === '') {
            return "\x00";
        }

        $labels = explode('.', $trimmed);
        $labelCount = \count($labels);

        if ($labelCount > self::MAX_LABELS) {
            throw new \InvalidArgumentException("Domain has too many labels: $labelCount");
        }

        $encoded = '';
        $totalLength = 0;

        foreach ($labels as $label) {
            if ($label === '') {
                throw new \InvalidArgumentException('Domain labels must not be empty');
            }

            if (str_contains($label, '@')) {
                throw new \InvalidArgumentException('Domain label contains invalid characters');
            }

            $labelLength = \strlen($label);

            if ($labelLength > self::MAX_LABEL_LEN) {
                throw new \InvalidArgumentException("Label too long: $label");
            }

            $encoded .= \chr($labelLength) . $label;
            $totalLength += $labelLength + 1; // length byte + label
        }

        $totalLength += 1; // trailing zero-length octet

        if ($totalLength > self::MAX_DOMAIN_NAME_LEN) {
            throw new \InvalidArgumentException(
                'Encoded domain exceeds maximum length of ' . self::MAX_DOMAIN_NAME_LEN . ' bytes',
            );
        }

        return $encoded . "\x00";
    }

    /**
     * Decode a domain name from DNS wire format, handling compression pointers.
     *
     * Per RFC 1035 Section 4.1.4, compression pointers allow domain names to
     * reference earlier occurrences in the packet. This implementation tracks
     * visited pointer positions to prevent infinite loops from malicious packets.
     *
     * @param string $data   Full DNS packet
     * @param int    $offset Current read offset (updated to first byte after the name)
     * @return string Decoded domain name in dotted form
     *
     * @throws DecodingException when the packet is malformed or contains pointer loops.
     */
    public static function decode(string $data, int &$offset): string
    {
        $labels = [];
        $jumped = false;
        $pos = $offset;
        $dataLength = \strlen($data);

        // Track visited pointer positions to detect loops (RFC 1035 compliance)
        // This is more reliable than iteration counting as it catches actual cycles
        $visitedPointers = [];

        // Maximum labels per RFC 1035 (127 labels * 63 chars + separators = 255 max)
        $labelCount = 0;

        while (true) {
            if ($pos >= $dataLength) {
                throw new DecodingException(
                    'Unexpected end of data while decoding domain name',
                );
            }

            $len = \ord($data[$pos]);
            if ($len === 0) {
                if (!$jumped) {
                    $offset = $pos + 1;
                }
                break;
            }

            if (($len & 0xC0) === 0xC0) {
                if ($pos + 1 >= $dataLength) {
                    throw new DecodingException(
                        'Truncated compression pointer in domain name',
                    );
                }

                $pointer = (($len & 0x3F) << 8) | \ord($data[$pos + 1]);

                // RFC 1035: Pointer must reference earlier in packet (forward refs invalid)
                if ($pointer >= $pos) {
                    throw new DecodingException(
                        'Compression pointer must reference earlier position in packet',
                    );
                }

                if ($pointer >= $dataLength) {
                    throw new DecodingException(
                        'Compression pointer out of bounds in domain name',
                    );
                }

                // Detect pointer loops by tracking visited positions
                if (isset($visitedPointers[$pointer])) {
                    throw new DecodingException(
                        'Compression pointer loop detected in domain name',
                    );
                }
                $visitedPointers[$pointer] = true;

                if (!$jumped) {
                    $offset = $pos + 2;
                }
                $pos = $pointer;
                $jumped = true;
                continue;
            }

            // Check for reserved label type (RFC 1035: bits 6-7 indicate label type)
            // 00 = standard label, 11 = compression pointer, 01/10 = reserved
            if (($len & 0xC0) !== 0) {
                throw new DecodingException(
                    'Reserved label type encountered in domain name',
                );
            }

            if ($pos + 1 + $len > $dataLength) {
                throw new DecodingException(
                    'Label length exceeds remaining data while decoding domain name',
                );
            }

            $labels[] = substr($data, $pos + 1, $len);
            $labelCount++;
            $pos += $len + 1;

            if ($labelCount > self::MAX_LABELS) {
                throw new DecodingException(
                    'Domain name exceeds maximum label count',
                );
            }

            if (!$jumped) {
                $offset = $pos;
            }
        }

        return implode('.', $labels);
    }
}
