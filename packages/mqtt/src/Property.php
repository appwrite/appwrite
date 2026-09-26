<?php

namespace Utopia\Mqtt;

/**
 * A single MQTT 5.0 property: an identifier plus a value whose wire encoding is
 * fixed by the identifier (a byte, a 2/4-byte integer, a variable byte integer, a
 * UTF-8 string / binary blob, or — for User Property — a string pair). Build one
 * with a constant and a value; {@see Properties} collects them into a block.
 *
 * User Property (0x26) carries a key/value map; encoding emits one wire pair per
 * entry.
 */
class Property
{
    // Property identifiers (MQTT 5.0, section 2.2.2.2).
    public const PAYLOAD_FORMAT_INDICATOR = 0x01;
    public const MESSAGE_EXPIRY_INTERVAL = 0x02;
    public const CONTENT_TYPE = 0x03;
    public const RESPONSE_TOPIC = 0x08;
    public const CORRELATION_DATA = 0x09;
    public const SUBSCRIPTION_IDENTIFIER = 0x0B;
    public const SESSION_EXPIRY_INTERVAL = 0x11;
    public const ASSIGNED_CLIENT_IDENTIFIER = 0x12;
    public const SERVER_KEEP_ALIVE = 0x13;
    public const AUTHENTICATION_METHOD = 0x15;
    public const AUTHENTICATION_DATA = 0x16;
    public const REQUEST_PROBLEM_INFORMATION = 0x17;
    public const WILL_DELAY_INTERVAL = 0x18;
    public const REQUEST_RESPONSE_INFORMATION = 0x19;
    public const RESPONSE_INFORMATION = 0x1A;
    public const SERVER_REFERENCE = 0x1C;
    public const REASON_STRING = 0x1F;
    public const RECEIVE_MAXIMUM = 0x21;
    public const TOPIC_ALIAS_MAXIMUM = 0x22;
    public const TOPIC_ALIAS = 0x23;
    public const MAXIMUM_QOS = 0x24;
    public const RETAIN_AVAILABLE = 0x25;
    public const USER = 0x26;
    public const MAXIMUM_PACKET_SIZE = 0x27;
    public const WILDCARD_SUBSCRIPTION_AVAILABLE = 0x28;
    public const SUBSCRIPTION_IDENTIFIER_AVAILABLE = 0x29;
    public const SHARED_SUBSCRIPTION_AVAILABLE = 0x2A;

    // Wire types.
    public const TYPE_BYTE = 'byte';
    public const TYPE_INT16 = 'int16';
    public const TYPE_INT32 = 'int32';
    public const TYPE_VARINT = 'varint';
    public const TYPE_STRING = 'string'; // UTF-8 string or binary data (length-prefixed)
    public const TYPE_PAIR = 'pair';     // User Property key/value

    public function __construct(
        public readonly int $id,
        public readonly mixed $value,
    ) {
    }

    /** The wire type for a property identifier. */
    public static function wireType(int $id): string
    {
        return match ($id) {
            self::PAYLOAD_FORMAT_INDICATOR,
            self::REQUEST_PROBLEM_INFORMATION,
            self::REQUEST_RESPONSE_INFORMATION,
            self::MAXIMUM_QOS,
            self::RETAIN_AVAILABLE,
            self::WILDCARD_SUBSCRIPTION_AVAILABLE,
            self::SUBSCRIPTION_IDENTIFIER_AVAILABLE,
            self::SHARED_SUBSCRIPTION_AVAILABLE => self::TYPE_BYTE,

            self::SERVER_KEEP_ALIVE,
            self::RECEIVE_MAXIMUM,
            self::TOPIC_ALIAS_MAXIMUM,
            self::TOPIC_ALIAS => self::TYPE_INT16,

            self::MESSAGE_EXPIRY_INTERVAL,
            self::SESSION_EXPIRY_INTERVAL,
            self::WILL_DELAY_INTERVAL,
            self::MAXIMUM_PACKET_SIZE => self::TYPE_INT32,

            self::SUBSCRIPTION_IDENTIFIER => self::TYPE_VARINT,

            self::CONTENT_TYPE,
            self::RESPONSE_TOPIC,
            self::CORRELATION_DATA,
            self::ASSIGNED_CLIENT_IDENTIFIER,
            self::AUTHENTICATION_METHOD,
            self::AUTHENTICATION_DATA,
            self::RESPONSE_INFORMATION,
            self::SERVER_REFERENCE,
            self::REASON_STRING => self::TYPE_STRING,

            self::USER => self::TYPE_PAIR,

            default => throw new \RuntimeException('Unknown MQTT property 0x' . dechex($id)),
        };
    }

    /** Encode this property (identifier byte + value) onto the wire. */
    public function encode(): string
    {
        $type = self::wireType($this->id);

        if ($type === self::TYPE_PAIR) {
            $out = '';
            /** @var array<string, string> $pairs */
            $pairs = \is_array($this->value) ? $this->value : [];
            foreach ($pairs as $key => $value) {
                $out .= chr($this->id) . Packet::encodeString((string) $key) . Packet::encodeString((string) $value);
            }
            return $out;
        }

        $value = match ($type) {
            self::TYPE_BYTE => chr((int) $this->value & 0xFF),
            self::TYPE_INT16 => chr(((int) $this->value >> 8) & 0xFF) . chr((int) $this->value & 0xFF),
            self::TYPE_INT32 => chr(((int) $this->value >> 24) & 0xFF)
                . chr(((int) $this->value >> 16) & 0xFF)
                . chr(((int) $this->value >> 8) & 0xFF)
                . chr((int) $this->value & 0xFF),
            self::TYPE_VARINT => Packet::encodeLength((int) $this->value),
            default => Packet::encodeString((string) $this->value), // TYPE_STRING
        };

        return chr($this->id) . $value;
    }
}
