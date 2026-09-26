<?php

namespace Utopia\Mqtt\Packet;

use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Properties;
use Utopia\Mqtt\Property;

class Auth
{
    public const SUCCESS = 0x00;
    public const CONTINUE = 0x18;
    public const REAUTH = 0x19;

    private function __construct(
        public readonly int $reasonCode,
        public readonly string $method,
        public readonly string $data,
        public readonly ?Properties $properties = null,
    ) {
    }

    public static function decode(string $body): self
    {
        if ($body === '') {
            return new self(self::SUCCESS, '', '');
        }

        [$reasonCode, $offset] = Packet::readByte($body, 0);
        [$properties] = Properties::parse($body, $offset);
        $method = $properties->get(Property::AUTHENTICATION_METHOD);
        $data = $properties->get(Property::AUTHENTICATION_DATA);

        return new self(
            $reasonCode,
            \is_string($method) ? $method : '',
            \is_string($data) ? $data : '',
            $properties,
        );
    }

    public static function success(string $method = ''): self
    {
        return new self(self::SUCCESS, $method, '');
    }

    public static function challenge(string $method, string $data): self
    {
        return new self(self::CONTINUE, $method, $data);
    }

    /** @return array<string, string> */
    public function userProperties(): array
    {
        return $this->properties?->user() ?? [];
    }
}
