<?php

namespace Utopia\Mqtt;

class Packet
{
    // Control packet types (MQTT fixed header, high nibble).
    public const CONNECT = 1;
    public const CONNACK = 2;
    public const PUBLISH = 3;
    public const PUBACK = 4;
    public const SUBSCRIBE = 8;
    public const SUBACK = 9;
    public const UNSUBSCRIBE = 10;
    public const UNSUBACK = 11;
    public const PINGREQ = 12;
    public const PINGRESP = 13;
    public const DISCONNECT = 14;
    public const AUTH = 15;

    public const QOS_1 = 1;

    public function __construct(
        public readonly int $type,
        public readonly int $flags,
        public readonly string $body,
    ) {
    }

    /**
     * Decode a framed packet into its fixed-header type/flags and remaining body.
     * A stream framed with MQTT semantics (e.g. Swoole's open_mqtt_protocol)
     * delivers exactly one packet per read.
     */
    public static function parse(string $data): self
    {
        $type = ord($data[0]) >> 4;
        $flags = ord($data[0]) & 0x0F;

        [$remaining, $lenBytes] = self::decodeLength($data, 1);
        $body = substr($data, 1 + $lenBytes, $remaining);

        return new self($type, $flags, $body);
    }

    public function name(): string
    {
        return match ($this->type) {
            self::CONNECT => 'connect',
            self::CONNACK => 'connack',
            self::PUBLISH => 'publish',
            self::PUBACK => 'puback',
            self::SUBSCRIBE => 'subscribe',
            self::SUBACK => 'suback',
            self::UNSUBSCRIBE => 'unsubscribe',
            self::UNSUBACK => 'unsuback',
            self::PINGREQ => 'pingreq',
            self::PINGRESP => 'pingresp',
            self::DISCONNECT => 'disconnect',
            self::AUTH => 'auth',
            default => 'unknown',
        };
    }

    /** The QoS of a PUBLISH, read from its fixed-header flags. */
    public function qos(): int
    {
        return ($this->flags >> 1) & 0x03;
    }

    /**
     * Clean Start (5.0) / Clean Session (3.1.1) of a CONNECT: bit 1 of the
     * connect-flags byte. Same position in both protocol versions.
     */
    public static function isCleanStart(string $body): bool
    {
        [, $offset] = self::readString($body, 0); // protocol name

        return (ord($body[$offset + 1]) & 0x02) === 0x02; // past protocol level
    }

    /**
     * Client Identifier of a CONNECT: the first payload field. Present in both
     * versions; on 5.0 it sits after the property block, so that block is skipped.
     */
    public static function getClientId(string $body): string
    {
        [, $offset] = self::readString($body, 0); // protocol name
        $level = ord($body[$offset]);
        $offset += 4; // protocol level + connect flags + keep alive

        if ($level >= 5) {
            [$length, $lenBytes] = self::decodeLength($body, $offset); // property block
            $offset += $lenBytes + $length;
        }

        [$clientId] = self::readString($body, $offset);

        return $clientId;
    }

    public static function pingreq(): string
    {
        return chr(self::PINGREQ << 4) . self::encodeLength(0);
    }

    public static function pingresp(): string
    {
        return chr(self::PINGRESP << 4) . self::encodeLength(0);
    }

    // --- Primitives ---------------------------------------------------------------

    /** @return array{0: string, 1: int} decoded string and the new offset */
    public static function readString(string $data, int $offset): array
    {
        $length = (ord($data[$offset]) << 8) + ord($data[$offset + 1]);
        $value = substr($data, $offset + 2, $length);

        return [$value, $offset + 2 + $length];
    }

    public static function encodeString(string $value): string
    {
        $length = strlen($value);

        return chr($length >> 8) . chr($length & 0xFF) . $value;
    }

    /** Decode a variable-length integer. @return array{0: int, 1: int} value and byte count */
    public static function decodeLength(string $data, int $offset): array
    {
        $value = 0;
        $multiplier = 1;
        $bytes = 0;
        do {
            $byte = ord($data[$offset + $bytes]);
            $value += ($byte & 0x7F) * $multiplier;
            $multiplier *= 128;
            $bytes++;
        } while (($byte & 0x80) !== 0);

        return [$value, $bytes];
    }

    public static function encodeLength(int $length): string
    {
        $out = '';
        do {
            $byte = $length % 128;
            $length = intdiv($length, 128);
            if ($length > 0) {
                $byte |= 0x80;
            }
            $out .= chr($byte);
        } while ($length > 0);

        return $out;
    }
}
