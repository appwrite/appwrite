<?php

namespace Utopia\Mqtt;

/**
 * An ordered collection of {@see Property}, i.e. an MQTT 5.0 property block. It
 * encodes to the variable-length length prefix followed by each property, and
 * parses the same shape back. Only MQTT 5.0 packets carry properties; 3.1.1 has
 * none.
 */
class Properties
{
    /** @var array<int, Property> */
    private array $properties = [];

    public function add(Property $property): self
    {
        $this->properties[] = $property;

        return $this;
    }

    /** @return array<int, Property> */
    public function all(): array
    {
        return $this->properties;
    }

    /** The value of the first property with this identifier, or null if absent. */
    public function get(int $id): mixed
    {
        foreach ($this->properties as $property) {
            if ($property->id === $id) {
                return $property->value;
            }
        }

        return null;
    }

    /**
     * The merged User Property key/value map.
     *
     * @return array<string, string>
     */
    public function user(): array
    {
        $user = [];
        foreach ($this->properties as $property) {
            if ($property->id === Property::USER && \is_array($property->value)) {
                $user = array_merge($user, $property->value);
            }
        }

        return $user;
    }

    /** Encode the property block: variable-length length prefix + each property. */
    public function encode(): string
    {
        $body = '';
        foreach ($this->properties as $property) {
            $body .= $property->encode();
        }

        return Packet::encodeLength(strlen($body)) . $body;
    }

    /**
     * Parse a property block at $offset.
     *
     * @return array{0: self, 1: int} the collection and the offset past the block
     */
    public static function parse(string $data, int $offset): array
    {
        $properties = new self();

        [$length, $lenBytes] = Packet::decodeLength($data, $offset);
        $offset += $lenBytes;
        $end = $offset + $length;

        while ($offset < $end) {
            $id = ord($data[$offset]);
            $offset++;
            [$value, $offset] = self::readValue($id, $data, $offset);
            $properties->add(new Property($id, $value));
        }

        return [$properties, $offset];
    }

    /** Advance past a property block without materializing its values. */
    public static function skip(string $data, int $offset): int
    {
        [$length, $lenBytes] = Packet::decodeLength($data, $offset);

        return $offset + $lenBytes + $length;
    }

    /**
     * Read one property value by its wire type.
     *
     * @return array{0: mixed, 1: int} the value and the new offset
     */
    private static function readValue(int $id, string $data, int $offset): array
    {
        switch (Property::wireType($id)) {
            case Property::TYPE_BYTE:
                return [ord($data[$offset]), $offset + 1];
            case Property::TYPE_INT16:
                return [(ord($data[$offset]) << 8) + ord($data[$offset + 1]), $offset + 2];
            case Property::TYPE_INT32:
                $value = (ord($data[$offset]) << 24)
                    + (ord($data[$offset + 1]) << 16)
                    + (ord($data[$offset + 2]) << 8)
                    + ord($data[$offset + 3]);
                return [$value, $offset + 4];
            case Property::TYPE_VARINT:
                [$value, $bytes] = Packet::decodeLength($data, $offset);
                return [$value, $offset + $bytes];
            case Property::TYPE_PAIR:
                [$key, $offset] = Packet::readString($data, $offset);
                [$value, $offset] = Packet::readString($data, $offset);
                return [[$key => $value], $offset];
            default: // Property::TYPE_STRING
                return Packet::readString($data, $offset);
        }
    }
}
