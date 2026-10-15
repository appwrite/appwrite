<?php

namespace Utopia\Migration\Resources\Auth;

use Utopia\Migration\Resource;
use Utopia\Migration\Transfer;

class User extends Resource
{
    /**
     * @param string $id
     * @param string $email
     * @param string $username
     * @param ?Hash $passwordHash
     * @param ?string $phone
     * @param array<string> $labels
     * @param string $oauthProvider
     * @param bool $emailVerified
     * @param bool $phoneVerified
     * @param bool $disabled
     * @param array<string, mixed> $preferences
     * @param array<array<string, mixed>> $targets
     */
    public function __construct(
        string $id,
        private readonly ?string $email = '',
        private readonly ?string $username = '',
        private readonly ?Hash $passwordHash = null,
        private readonly ?string $phone = null,
        private readonly array $labels = [],
        private readonly string $oauthProvider = '',
        private readonly bool $emailVerified = false,
        private readonly bool $phoneVerified = false,
        private readonly bool $disabled = false,
        private readonly array $preferences = [],
        private readonly array $targets = [],
    ) {
        $this->id = $id;
    }

    /**
     * @param array<string, mixed> $array
     * @return self
     */
    public static function fromArray(array $array): self
    {
        return new self(
            $array['id'],
            $array['email'] ?? '',
            $array['username'] ?? '',
            $array['passwordHash'] ?? null,
            $array['phone'] ?? '',
            $array['labels'] ?? [],
            $array['oauthProvider'] ?? '',
            $array['emailVerified'] ?? false,
            $array['phoneVerified'] ?? false,
            $array['disabled'] ?? false,
            $array['preferences'] ?? [],
            $array['targets'] ?? [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'username' => $this->username,
            'passwordHash' => $this->passwordHash,
            'phone' => $this->phone,
            'labels' => $this->labels,
            'oauthProvider' => $this->oauthProvider,
            'emailVerified' => $this->emailVerified,
            'phoneVerified' => $this->phoneVerified,
            'disabled' => $this->disabled,
            'preferences' => $this->preferences,
            'targets' => $this->targets,
        ];
    }

    /**
     * Get Name
     */
    public static function getName(): string
    {
        return Resource::TYPE_USER;
    }

    /**
     * Get Email
     */
    public function getEmail(): ?string
    {
        return $this->email;
    }
    /**
     * Get Username
     */
    public function getUsername(): ?string
    {
        return $this->username;
    }

    /**
     * Get Password Hash
     */
    public function getPasswordHash(): ?Hash
    {
        return $this->passwordHash;
    }

    /**
     * Get Phone
     */
    public function getPhone(): ?string
    {
        return $this->phone;
    }

    /**
     * Get Labels
     *
     * @return array<string>
     */
    public function getLabels(): array
    {
        return $this->labels;
    }

    /**
     * Get OAuth Provider
     */
    public function getOAuthProvider(): string
    {
        return $this->oauthProvider;
    }

    /**
     * Get Email Verified
     */
    public function getEmailVerified(): bool
    {
        return $this->emailVerified;
    }

    /**
     * Get Email Verified
     */
    public function getPhoneVerified(): bool
    {
        return $this->phoneVerified;
    }

    public function getGroup(): string
    {
        return Transfer::GROUP_AUTH;
    }

    /**
     * Get Disabled
     */
    public function getDisabled(): bool
    {
        return $this->disabled;
    }

    /**
     * Get Preferences
     *
     * @return array<string, mixed>
     */
    public function getPreferences(): array
    {
        return $this->preferences;
    }

    /**
     * Get Targets
     *
     * @return array<array<string, mixed>>
     */
    public function getTargets(): array
    {
        return $this->targets;
    }
}
