<?php

namespace Appwrite\SDK;

class Deprecated
{
    /**
     * @param string $since
     * @param string|null $replaceWith
     */
    public function __construct(
        private string $since,
        private ?string $replaceWith = null,
    ) {
    }

    /**
     * @return string
     */
    public function getSince(): string
    {
        return $this->since;
    }

    /**
     * @return string|null
     */
    public function getReplaceWith(): ?string
    {
        return $this->replaceWith;
    }
}
