<?php

namespace Appwrite\Event\Context;

use Utopia\Database\Document;

class Audit
{
    public function __construct(
        public ?Document $project = null,
        public ?Document $user = null,
        public string $mode = '',
        public string $userAgent = '',
        public string $ip = '',
        public string $hostname = '',
        public string $event = '',
        public string $resource = '',
        public array $payload = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->project === null
            && $this->user === null
            && $this->mode === ''
            && $this->userAgent === ''
            && $this->ip === ''
            && $this->hostname === ''
            && $this->event === ''
            && $this->resource === ''
            && $this->payload === [];
    }
}
