<?php

namespace Utopia\Mqtt\Packet;

use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Properties;

class Unsubscribe
{
    /** @param list<string> $filters */
    private function __construct(
        public readonly int $packetId,
        private readonly array $filters,
        public readonly ?Properties $properties = null,
    ) {
    }

    public static function decode(string $body, int $protocol): self
    {
        [$packetId, $offset] = Packet::readInt16($body, 0);

        $properties = null;
        if ($protocol >= 5) {
            [$properties, $offset] = Properties::parse($body, $offset);
        }

        $filters = [];
        while ($offset < strlen($body)) {
            [$filter, $offset] = Packet::readString($body, $offset);
            $filters[] = $filter;
        }

        return new self($packetId, $filters, $properties);
    }

    /** @return list<string> */
    public function filters(): array
    {
        return $this->filters;
    }
}
