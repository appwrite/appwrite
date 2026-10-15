<?php

namespace Utopia\Mqtt\Packet\Specs;

use Utopia\Mqtt\Packet;

/**
 * MQTT 3.1.1 control-packet encoders. There are no property blocks and no reason
 * codes: CONNACK carries a return code, SUBACK carries a granted-QoS (or failure)
 * byte per filter, and there is no AUTH packet.
 */
class V3
{
    public const PROTOCOL_LEVEL = 4;

    // CONNACK return codes (MQTT 3.1.1, section 3.2.2.3).
    public const RETURN_ACCEPTED = 0x00;
    public const RETURN_UNACCEPTABLE_PROTOCOL = 0x01;
    public const RETURN_IDENTIFIER_REJECTED = 0x02;
    public const RETURN_SERVER_UNAVAILABLE = 0x03;
    public const RETURN_BAD_CREDENTIALS = 0x04;
    public const RETURN_NOT_AUTHORIZED = 0x05;

    // SUBACK failure marker (a granted-QoS byte otherwise).
    public const SUBSCRIBE_FAILURE = 0x80;

    /** CONNECT: protocol header + client id (+ username/password for authentication). */
    public static function connect(string $clientId, int $keepAlive = 60, bool $cleanSession = true, string $username = '', string $password = ''): string
    {
        $flags = $cleanSession ? 0x02 : 0x00;
        if ($username !== '') {
            $flags |= 0x80;
        }
        if ($password !== '') {
            $flags |= 0x40;
        }

        $variable = Packet::encodeString('MQTT')
            . chr(self::PROTOCOL_LEVEL)
            . chr($flags)
            . chr($keepAlive >> 8) . chr($keepAlive & 0xFF);

        $payload = Packet::encodeString($clientId);
        if ($username !== '') {
            $payload .= Packet::encodeString($username);
        }
        if ($password !== '') {
            $payload .= Packet::encodeString($password);
        }

        $body = $variable . $payload;

        return chr(Packet::CONNECT << 4) . Packet::encodeLength(strlen($body)) . $body;
    }

    /**
     * SUBSCRIBE: packet id, then each topic filter with its granted-QoS options byte.
     *
     * @param array<int, string> $filters
     */
    public static function subscribe(int $packetId, array $filters, int $qos = 0): string
    {
        $variable = chr($packetId >> 8) . chr($packetId & 0xFF);
        foreach ($filters as $filter) {
            $variable .= Packet::encodeString($filter) . chr($qos);
        }

        return chr((Packet::SUBSCRIBE << 4) | 0x02) . Packet::encodeLength(strlen($variable)) . $variable;
    }

    /**
     * UNSUBSCRIBE: packet id, then each topic filter.
     *
     * @param array<int, string> $filters
     */
    public static function unsubscribe(int $packetId, array $filters): string
    {
        $variable = chr($packetId >> 8) . chr($packetId & 0xFF);
        foreach ($filters as $filter) {
            $variable .= Packet::encodeString($filter);
        }

        return chr((Packet::UNSUBSCRIBE << 4) | 0x02) . Packet::encodeLength(strlen($variable)) . $variable;
    }

    /** CONNACK: [ack flags = 0][return code]. */
    public static function connack(int $returnCode): string
    {
        $variable = chr(0x00) . chr($returnCode);

        return chr(Packet::CONNACK << 4) . Packet::encodeLength(strlen($variable)) . $variable;
    }

    /**
     * PUBLISH: topic, packet id (QoS > 0), then payload. Set $dup to mark a re-delivery
     * of an earlier QoS 1 attempt; it is ignored on QoS 0, where the DUP flag must be 0.
     */
    public static function publish(string $topic, string $payload, int $qos, int $packetId, bool $dup = false): string
    {
        $variable = Packet::encodeString($topic);
        if ($qos > 0) {
            $variable .= chr($packetId >> 8) . chr($packetId & 0xFF);
        }
        $variable .= $payload;

        $flags = ($qos << 1) | ($dup && $qos > 0 ? 0x08 : 0);

        return chr((Packet::PUBLISH << 4) | $flags) . Packet::encodeLength(strlen($variable)) . $variable;
    }

    /** PUBACK: the two-byte packet id. */
    public static function puback(string $packetId): string
    {
        return chr(Packet::PUBACK << 4) . Packet::encodeLength(strlen($packetId)) . $packetId;
    }

    /** SUBACK: packet id, then one granted-QoS (or 0x80 failure) byte per filter. */
    public static function suback(string $packetId, string $returnCodes): string
    {
        $variable = $packetId . $returnCodes;

        return chr(Packet::SUBACK << 4) . Packet::encodeLength(strlen($variable)) . $variable;
    }

    /** UNSUBACK: the two-byte packet id (no reason codes in 3.1.1). */
    public static function unsuback(string $packetId): string
    {
        return chr(Packet::UNSUBACK << 4) . Packet::encodeLength(strlen($packetId)) . $packetId;
    }

    /** DISCONNECT: no reason code, no properties. */
    public static function disconnect(): string
    {
        return chr(Packet::DISCONNECT << 4) . Packet::encodeLength(0);
    }
}
