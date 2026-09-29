<?php

declare(strict_types=1);

namespace Utopia\NATS\Services;

use Utopia\NATS\Message;

/**
 * An endpoint group: a subject prefix (dot-joined) plus an optional
 * default queue group inherited by the endpoints and nested groups
 * registered under it. See the NATS micro spec / ADR-32.
 */
final class Group
{
    public function __construct(
        private readonly Service $service,
        private readonly string $prefix,
        private readonly ?string $queueGroup = null,
    ) {}

    /**
     * Create a nested group. Its subject prefix is this group's prefix
     * joined with $name; the queue group is inherited unless overridden.
     */
    public function addGroup(string $name, ?string $queueGroup = null): self
    {
        return new self(
            $this->service,
            $this->prefix . '.' . $name,
            $queueGroup ?? $this->queueGroup,
        );
    }

    /**
     * Register an endpoint under this group. The subject is prefixed with
     * the group name; when $subject is omitted the endpoint name is used.
     *
     * @param callable(Message): string $handler
     * @param array<string, string> $metadata
     */
    public function addEndpoint(
        string $name,
        callable $handler,
        ?string $subject = null,
        ?string $queueGroup = null,
        array $metadata = [],
    ): self {
        $this->service->registerEndpoint(
            $name,
            $this->prefix . '.' . ($subject ?? $name),
            $handler,
            $queueGroup ?? $this->queueGroup,
            $metadata,
        );

        return $this;
    }
}
