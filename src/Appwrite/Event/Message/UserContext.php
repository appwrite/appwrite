<?php

namespace Appwrite\Event\Message;

use Utopia\Database\Document;

final readonly class UserContext
{
    public function __construct(
        public string $id = '',
        public string $sequence = '',
        public string $name = '',
        public string $email = '',
        public string $type = '',
    ) {
    }

    public static function fromDocument(Document $user): self
    {
        return new self(
            id: $user->getId(),
            sequence: (string) $user->getSequence(),
            name: (string) $user->getAttribute('name', ''),
            email: (string) $user->getAttribute('email', ''),
            type: (string) $user->getAttribute('type', ''),
        );
    }

    public static function fromArray(array $user): self
    {
        return new self(
            id: (string) ($user['$id'] ?? ''),
            sequence: (string) ($user['$sequence'] ?? ''),
            name: (string) ($user['name'] ?? ''),
            email: (string) ($user['email'] ?? ''),
            type: (string) ($user['type'] ?? ''),
        );
    }

    public function isEmpty(): bool
    {
        return $this->toArray() === [];
    }

    public function toArray(): array
    {
        return \array_filter([
            '$id' => $this->id,
            '$sequence' => $this->sequence,
            'name' => $this->name,
            'email' => $this->email,
            'type' => $this->type,
        ], static fn (string $value): bool => $value !== '');
    }
}
