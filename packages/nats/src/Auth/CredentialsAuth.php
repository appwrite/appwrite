<?php

declare(strict_types=1);

namespace Utopia\NATS\Auth;

use Utopia\NATS\Exception\AuthenticationException;

final class CredentialsAuth implements Authenticator
{
    private readonly string $jwt;
    private readonly NKeyAuth $nkeyAuth;

    public function __construct(string $credentialsFile)
    {
        if (!file_exists($credentialsFile)) {
            throw new AuthenticationException("Credentials file not found: {$credentialsFile}");
        }

        $contents = file_get_contents($credentialsFile);
        if ($contents === false) {
            throw new AuthenticationException("Failed to read credentials file: {$credentialsFile}");
        }

        $jwt = $this->extractBetween($contents, '-----BEGIN NATS USER JWT-----', '------END NATS USER JWT------');
        $seed = $this->extractBetween($contents, '-----BEGIN USER NKEY SEED-----', '------END USER NKEY SEED------');

        if ($jwt === null) {
            throw new AuthenticationException('No JWT found in credentials file');
        }
        if ($seed === null) {
            throw new AuthenticationException('No NKey seed found in credentials file');
        }

        $this->jwt = $jwt;
        // NKeyAuth derives the real public key from the seed when none is given,
        // so authenticate() below sends the correct nkey rather than an empty one.
        $this->nkeyAuth = new NKeyAuth('', $seed);
    }

    public function authenticate(?string $nonce = null): array
    {
        $nkeyFields = $this->nkeyAuth->authenticate($nonce);

        return [
            'jwt' => $this->jwt,
            'nkey' => $nkeyFields['nkey'] ?? '',
            'sig' => $nkeyFields['sig'] ?? '',
        ];
    }

    private function extractBetween(string $content, string $begin, string $end): ?string
    {
        $startPos = strpos($content, $begin);
        if ($startPos === false) {
            return null;
        }

        $startPos += \strlen($begin);
        $endPos = strpos($content, $end, $startPos);
        if ($endPos === false) {
            return null;
        }

        return trim(substr($content, $startPos, $endPos - $startPos));
    }
}
