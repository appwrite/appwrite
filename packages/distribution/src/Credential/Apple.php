<?php

declare(strict_types=1);

namespace Utopia\Distribution\Credential;

use Utopia\Distribution\Credential;
use Utopia\Distribution\Exception\Invalid;

final readonly class Apple extends Credential
{
    public function __construct(
        public string $keyId,
        public string $issuerId,
        #[\SensitiveParameter]
        public string $privateKey,
    ) {
        if ($keyId === '') {
            throw new Invalid('Apple key id must not be empty');
        }

        if ($issuerId === '') {
            throw new Invalid('Apple issuer id must not be empty: individual keys are not supported, use a team key');
        }

        if ($privateKey === '') {
            throw new Invalid('Apple private key must not be empty');
        }

        if (!self::isPem($privateKey)) {
            throw new Invalid('Apple private key must be the PEM text of the .p8 file, not a path');
        }
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['keyId' => $this->keyId, 'issuerId' => $this->issuerId, 'privateKey' => self::REDACTED];
    }
}
