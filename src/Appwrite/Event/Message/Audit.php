<?php

namespace Appwrite\Event\Message;

use Appwrite\Event\Context\Audit as AuditContext;
use Utopia\Database\Document;

final class Audit extends Base
{
    public function __construct(
        public readonly string $event,
        public readonly array $payload,
        public readonly Document $project = new Document(),
        public readonly Document $user = new Document(),
        public readonly string $resource = '',
        public readonly string $mode = '',
        public readonly string $ip = '',
        public readonly string $userAgent = '',
        public readonly string $hostname = '',
    ) {
    }

    public function toArray(): array
    {
        return [
            'project' => [
                '$id' => $this->project->getId(),
                '$sequence' => $this->project->getSequence(),
                'database' => $this->project->getAttribute('database', ''),
            ],
            'user' => $this->user->getArrayCopy(),
            'payload' => $this->payload,
            'resource' => $this->resource,
            'mode' => $this->mode,
            'ip' => $this->ip,
            'userAgent' => $this->userAgent,
            'event' => $this->event,
            'hostname' => $this->hostname,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            event: $data['event'] ?? '',
            payload: $data['payload'] ?? [],
            project: new Document($data['project'] ?? []),
            user: new Document($data['user'] ?? []),
            resource: $data['resource'] ?? '',
            mode: $data['mode'] ?? '',
            ip: $data['ip'] ?? '',
            userAgent: $data['userAgent'] ?? '',
            hostname: $data['hostname'] ?? '',
        );
    }

    public static function fromContext(AuditContext $context): static
    {
        return new self(
            event: $context->event,
            payload: $context->payload,
            project: $context->project ?? new Document(),
            user: $context->user ?? new Document(),
            resource: $context->resource,
            mode: $context->mode,
            ip: $context->ip,
            userAgent: $context->userAgent,
            hostname: $context->hostname,
        );
    }
}
