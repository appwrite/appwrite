<?php

namespace Utopia\Mqtt\Packet;

use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Properties;

/**
 * MQTT 5.0 control-packet encoders. Every acknowledgement carries a reason code
 * and a {@see Properties} block (empty by default); PUBLISH carries the block
 * before its payload. Property blocks are parsed with {@see Properties::parse}.
 */
class V5
{
    public const PROTOCOL_LEVEL = 5;

    // Reason codes (MQTT 5.0). 0x00 is Success across every acknowledgement.
    public const REASON_SUCCESS = 0x00;
    public const REASON_NOT_AUTHORIZED = 0x87;
    public const AUTH_SUCCESS = 0x00;
    public const AUTH_CONTINUE = 0x18;
    public const AUTH_REAUTH = 0x19;

    /** CONNECT: protocol header, properties (auth method/data, metadata), then client id. */
    public static function connect(string $clientId, int $keepAlive = 60, bool $cleanStart = true, ?Properties $properties = null): string
    {
        $variable = Packet::encodeString('MQTT')
            . chr(self::PROTOCOL_LEVEL)
            . chr($cleanStart ? 0x02 : 0x00)
            . chr($keepAlive >> 8) . chr($keepAlive & 0xFF)
            . ($properties ?? new Properties())->encode();

        $body = $variable . Packet::encodeString($clientId);

        return chr(Packet::CONNECT << 4) . Packet::encodeLength(strlen($body)) . $body;
    }

    /**
     * SUBSCRIBE: packet id, properties, then each topic filter with its options byte.
     *
     * @param array<int, string> $filters
     */
    public static function subscribe(int $packetId, array $filters, int $qos = 0, ?Properties $properties = null): string
    {
        $variable = chr($packetId >> 8) . chr($packetId & 0xFF) . ($properties ?? new Properties())->encode();
        foreach ($filters as $filter) {
            $variable .= Packet::encodeString($filter) . chr($qos);
        }

        return chr((Packet::SUBSCRIBE << 4) | 0x02) . Packet::encodeLength(strlen($variable)) . $variable;
    }

    /**
     * UNSUBSCRIBE: packet id, properties, then each topic filter.
     *
     * @param array<int, string> $filters
     */
    public static function unsubscribe(int $packetId, array $filters, ?Properties $properties = null): string
    {
        $variable = chr($packetId >> 8) . chr($packetId & 0xFF) . ($properties ?? new Properties())->encode();
        foreach ($filters as $filter) {
            $variable .= Packet::encodeString($filter);
        }

        return chr((Packet::UNSUBSCRIBE << 4) | 0x02) . Packet::encodeLength(strlen($variable)) . $variable;
    }

    /** CONNACK: [ack flags = 0][reason code][properties]. */
    public static function connack(int $reasonCode, ?Properties $properties = null): string
    {
        $variable = chr(0x00) . chr($reasonCode) . ($properties ?? new Properties())->encode();

        return chr(Packet::CONNACK << 4) . Packet::encodeLength(strlen($variable)) . $variable;
    }

    /**
     * PUBLISH: topic, packet id (QoS > 0), properties, then payload. Set $dup to mark
     * a re-delivery of an earlier QoS 1 attempt; it is ignored on QoS 0, where the
     * DUP flag must be 0.
     */
    public static function publish(string $topic, string $payload, int $qos, int $packetId, ?Properties $properties = null, bool $dup = false): string
    {
        $variable = Packet::encodeString($topic);
        if ($qos > 0) {
            $variable .= chr($packetId >> 8) . chr($packetId & 0xFF);
        }
        $variable .= ($properties ?? new Properties())->encode() . $payload;

        $flags = ($qos << 1) | ($dup && $qos > 0 ? 0x08 : 0);

        return chr((Packet::PUBLISH << 4) | $flags) . Packet::encodeLength(strlen($variable)) . $variable;
    }

    /** PUBACK: the two-byte packet id (reason/properties omitted for Success). */
    public static function puback(string $packetId): string
    {
        return chr(Packet::PUBACK << 4) . Packet::encodeLength(strlen($packetId)) . $packetId;
    }

    /** SUBACK: packet id, properties, then one reason code per filter. */
    public static function suback(string $packetId, string $reasonCodes, ?Properties $properties = null): string
    {
        $variable = $packetId . ($properties ?? new Properties())->encode() . $reasonCodes;

        return chr(Packet::SUBACK << 4) . Packet::encodeLength(strlen($variable)) . $variable;
    }

    /** UNSUBACK: packet id, properties, then a Success reason code per filter. */
    public static function unsuback(string $packetId, int $count, ?Properties $properties = null): string
    {
        $variable = $packetId
            . ($properties ?? new Properties())->encode()
            . str_repeat(chr(self::REASON_SUCCESS), $count);

        return chr(Packet::UNSUBACK << 4) . Packet::encodeLength(strlen($variable)) . $variable;
    }

    /** DISCONNECT: reason code + properties. */
    public static function disconnect(int $reasonCode, ?Properties $properties = null): string
    {
        $variable = chr($reasonCode) . ($properties ?? new Properties())->encode();

        return chr(Packet::DISCONNECT << 4) . Packet::encodeLength(strlen($variable)) . $variable;
    }

    /** AUTH: reason code + properties (Authentication Method/Data live in the block). */
    public static function auth(int $reasonCode, ?Properties $properties = null): string
    {
        $variable = chr($reasonCode) . ($properties ?? new Properties())->encode();

        return chr(Packet::AUTH << 4) . Packet::encodeLength(strlen($variable)) . $variable;
    }
}
