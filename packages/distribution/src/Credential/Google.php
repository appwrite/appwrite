<?php

declare(strict_types=1);

namespace Utopia\Distribution\Credential;

use Utopia\Distribution\Credential;
use Utopia\Distribution\Exception\Invalid;

final readonly class Google extends Credential
{
    public function __construct(
        public string $email,
        #[\SensitiveParameter]
        public string $privateKey,
        public ?string $project = null,
    ) {
        if ($email === '') {
            throw new Invalid('Google service account email must not be empty');
        }

        if ($privateKey === '') {
            throw new Invalid('Google service account private key must not be empty');
        }

        if (!self::isPem($privateKey)) {
            throw new Invalid('Google service account private key must be PEM text, not a path');
        }

        if ($project === '') {
            throw new Invalid('Google project id must not be empty when given');
        }
    }

    /**
     * @return array<string, ?string>
     */
    public function __debugInfo(): array
    {
        return ['email' => $this->email, 'privateKey' => self::REDACTED, 'project' => $this->project];
    }

    /**
     * @param array<array-key, mixed> $serviceAccount the service account JSON key, decoded
     */
    public static function fromServiceAccount(#[\SensitiveParameter] array $serviceAccount): self
    {
        $email = $serviceAccount['client_email'] ?? null;
        $privateKey = $serviceAccount['private_key'] ?? null;
        $project = $serviceAccount['project_id'] ?? null;

        if (!\is_string($email)) {
            throw new Invalid('Google service account is missing client_email');
        }

        if (!\is_string($privateKey)) {
            throw new Invalid('Google service account is missing private_key');
        }

        if ($project !== null && !\is_string($project)) {
            throw new Invalid('Google service account project_id must be a string');
        }

        return new self($email, $privateKey, $project);
    }
}
