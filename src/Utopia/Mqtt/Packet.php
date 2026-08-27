<?php

namespace Utopia\Mqtt;

/**
 * MQTT control packet: the decoded fixed header (type + flags) and its remaining
 * body, plus the stateless wire codec shared by every handler. Encoding lives here
 * so the protocol byte-work has a single home and the handlers read as intent.
 */
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

    // Reason codes (MQTT 5.0). 0x00 is Success across every acknowledgement.
    public const REASON_SUCCESS = 0x00;
    public const REASON_NOT_AUTHORIZED = 0x87;
    public const AUTH_SUCCESS = 0x00;
    public const AUTH_CONTINUE = 0x18; // reserved for multi-round continue-auth
    public const AUTH_REAUTH = 0x19; // reserved for the reauth flow

    public const QOS_1 = 1;

    // Property identifiers, shared between CONNECT and AUTH property blocks.
    public const PROP_AUTH_METHOD = 0x15;
    public const PROP_AUTH_DATA = 0x16;
    public const PROP_USER = 0x26;

    public function __construct(
        public readonly int $type,
        public readonly int $flags,
        public readonly string $body,
    ) {
    }

    /**
     * Decode a framed packet into its fixed-header type/flags and remaining body.
     * Swoole's open_mqtt_protocol delivers exactly one packet per receive.
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
            self::SUBSCRIBE => 'subscribe',
            self::UNSUBSCRIBE => 'unsubscribe',
            self::PUBLISH => 'publish',
            self::PUBACK => 'puback',
            self::AUTH => 'auth',
            self::PINGREQ => 'pingreq',
            self::DISCONNECT => 'disconnect',
            default => 'unknown',
        };
    }

    /**
     * Encode an outbound PUBLISH for one subscriber. The variable header order is
     * topic, packet id (QoS > 0), v5 property block, then payload. The packet id and
     * protocol level are the subscriber's, not the publisher's.
     */
    public static function publish(string $topic, string $payload, int $qos, int $packetId, int $protocol): string
    {
        $variable = self::encodeString($topic);
        if ($qos > 0) {
            $variable .= chr($packetId >> 8) . chr($packetId & 0xFF);
        }
        $variable .= ($protocol >= 5 ? self::encodeLength(0) : '') . $payload;

        $header = chr((self::PUBLISH << 4) | ($qos << 1));

        return $header . self::encodeLength(strlen($variable)) . $variable;
    }

    /**
     * Read a v5 property block: User Properties as a key/value map plus the
     * Authentication Method and Data. Shared by CONNECT and AUTH, which carry an
     * identical block. No-op for MQTT 3.1.1 (no properties).
     *
     * @return array{user: array<string, string>, authMethod: string, authData: string}
     */
    public static function readProperties(string $body, int $offset, int $protocol): array
    {
        $properties = ['user' => [], 'authMethod' => '', 'authData' => ''];

        if ($protocol < 5) {
            return $properties;
        }

        [$length, $lenBytes] = self::decodeLength($body, $offset);
        $offset += $lenBytes;
        $end = $offset + $length;

        while ($offset < $end) {
            $id = ord($body[$offset]);
            $offset++;

            switch ($id) {
                case self::PROP_AUTH_METHOD:
                    [$properties['authMethod'], $offset] = self::readString($body, $offset);
                    break;
                case self::PROP_AUTH_DATA:
                    [$properties['authData'], $offset] = self::readString($body, $offset);
                    break;
                case self::PROP_USER:
                    [$key, $offset] = self::readString($body, $offset);
                    [$value, $offset] = self::readString($body, $offset);
                    $properties['user'][$key] = $value;
                    break;
                default:
                    // POC assumption: clients send only auth and user properties.
                    throw new \RuntimeException('Unhandled connect property 0x' . dechex($id));
            }
        }

        return $properties;
    }

    /**
     * Skip the MQTT 5.0 property block (variable-length length prefix + bytes).
     * No-op for MQTT 3.1.1, which has no properties.
     */
    public static function skipProperties(string $body, int $offset, int $protocol): int
    {
        if ($protocol < 5) {
            return $offset;
        }
        [$length, $lenBytes] = self::decodeLength($body, $offset);

        return $offset + $lenBytes + $length;
    }

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
