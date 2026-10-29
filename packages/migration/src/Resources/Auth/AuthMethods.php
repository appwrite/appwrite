<?php

namespace Utopia\Migration\Resources\Auth;

use Utopia\Migration\Resource;
use Utopia\Migration\Transfer;

/**
 * Singleton resource representing the project's auth-method flags.
 * One per project; the destination updates the project doc, not a new row.
 */
class AuthMethods extends Resource
{
    public function __construct(
        string $id,
        private readonly bool $emailPassword = true,
        private readonly bool $magicURL = true,
        private readonly bool $emailOtp = true,
        private readonly bool $anonymous = true,
        private readonly bool $invites = true,
        private readonly bool $jwt = true,
        private readonly bool $phone = true,
        string $createdAt = '',
        string $updatedAt = '',
    ) {
        $this->id = $id;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    /**
     * @param array<string, mixed> $array
     */
    public static function fromArray(array $array): self
    {
        return new self(
            $array['id'],
            (bool) ($array['emailPassword'] ?? true),
            (bool) ($array['magicURL'] ?? true),
            (bool) ($array['emailOtp'] ?? true),
            (bool) ($array['anonymous'] ?? true),
            (bool) ($array['invites'] ?? true),
            (bool) ($array['jwt'] ?? true),
            (bool) ($array['phone'] ?? true),
            createdAt: $array['createdAt'] ?? '',
            updatedAt: $array['updatedAt'] ?? '',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'emailPassword' => $this->emailPassword,
            'magicURL' => $this->magicURL,
            'emailOtp' => $this->emailOtp,
            'anonymous' => $this->anonymous,
            'invites' => $this->invites,
            'jwt' => $this->jwt,
            'phone' => $this->phone,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }

    public static function getName(): string
    {
        return Resource::TYPE_AUTH_METHODS;
    }

    public function getGroup(): string
    {
        return Transfer::GROUP_AUTH;
    }

    public function getEmailPassword(): bool
    {
        return $this->emailPassword;
    }

    public function getMagicURL(): bool
    {
        return $this->magicURL;
    }

    public function getEmailOtp(): bool
    {
        return $this->emailOtp;
    }

    public function getAnonymous(): bool
    {
        return $this->anonymous;
    }

    public function getInvites(): bool
    {
        return $this->invites;
    }

    public function getJwt(): bool
    {
        return $this->jwt;
    }

    public function getPhone(): bool
    {
        return $this->phone;
    }
}
