<?php

namespace Appwrite\Event\Message;

abstract class Base
{
    /**
     * Serialize message to array for queue
     *
     * @return array
     */
    abstract public function toArray(): array;

    /**
     * Deserialize message from array
     *
     * @param array $data
     * @return static
     */
    abstract public static function fromArray(array $data): static;
}
