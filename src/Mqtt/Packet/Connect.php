<?php

namespace Utopia\Mqtt\Packet;

use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Properties;
use Utopia\Mqtt\Property;

class Connect
{
    private function __construct(
        public readonly int $protocolLevel,
        public readonly string $clientId,
        public readonly bool $cleanStart,
        public readonly int $keepAlive,
        public readonly ?string $username,
        public readonly ?string $password,
        public readonly ?Will $will,
        public readonly ?string $authMethod,
        public readonly ?string $authData,
        public readonly ?Properties $properties = null,
    ) {
    }

    public static function decode(string $body): self
    {
        [, $offset] = Packet::readString($body, 0);
        $level = ord($body[$offset]);
        $offset++;
        $flags = ord($body[$offset]);
        $offset++;
        [$keepAlive, $offset] = Packet::readInt16($body, $offset);

        $properties = null;
        $authMethod = null;
        $authData = null;
        if ($level >= 5) {
            [$properties, $offset] = Properties::parse($body, $offset);
            $method = $properties->get(Property::AUTHENTICATION_METHOD);
            $data = $properties->get(Property::AUTHENTICATION_DATA);
            $authMethod = \is_string($method) ? $method : null;
            $authData = \is_string($data) ? $data : null;
        }

        [$clientId, $offset] = Packet::readString($body, $offset);

        $will = null;
        if (($flags & 0x04) === 0x04) {
            if ($level >= 5) {
                $offset = Properties::skip($body, $offset);
            }
            [$willTopic, $offset] = Packet::readString($body, $offset);
            [$willPayload, $offset] = Packet::readString($body, $offset);
            $will = new Will($willTopic, $willPayload, ($flags >> 3) & 0x03, ($flags & 0x20) === 0x20);
        }

        $username = null;
        if (($flags & 0x80) === 0x80) {
            [$username, $offset] = Packet::readString($body, $offset);
        }

        $password = null;
        if (($flags & 0x40) === 0x40) {
            [$password, $offset] = Packet::readString($body, $offset);
        }

        return new self(
            $level,
            $clientId,
            ($flags & 0x02) === 0x02,
            $keepAlive,
            $username,
            $password,
            $will,
            $authMethod,
            $authData,
            $properties,
        );
    }

    /** @return array<string, string> */
    public function userProperties(): array
    {
        return $this->properties?->user() ?? [];
    }
}
