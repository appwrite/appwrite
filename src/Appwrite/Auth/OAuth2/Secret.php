<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Extend\Exception;
use Appwrite\OpenSSL\OpenSSL;
use Utopia\Database\Document;
use Utopia\System\System;

class Secret
{
    /**
     * Normalize an OAuth2 provider secret to a plain string.
     *
     * Supports both the current storage format (plain string inside the
     * document-level encrypted oAuthProviders map) and the legacy <=1.8.x
     * format where individual secrets were nested encrypted objects.
     *
     * @throws Exception When the value is neither a string nor a legacy encrypted array.
     */
    public static function normalize(mixed $secret): string
    {
        if (\is_string($secret)) {
            return $secret;
        }

        if (\is_array($secret) && isset($secret['version'], $secret['data'], $secret['method'], $secret['iv'], $secret['tag'])) {
            $key = System::getEnv('_APP_OPENSSL_KEY_V' . $secret['version']);
            $decrypted = OpenSSL::decrypt(
                $secret['data'],
                $secret['method'],
                $key,
                0,
                \hex2bin($secret['iv']),
                \hex2bin($secret['tag'])
            );

            if ($decrypted === false) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to decrypt OAuth2 provider secret.');
            }

            return $decrypted;
        }

        throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Invalid OAuth2 provider secret format.');
    }

    /**
     * Read and normalize the secret for a provider from a project document.
     */
    public static function fromProject(Document $project, string $provider): string
    {
        $raw = $project->getAttribute('oAuthProviders', [])[$provider . 'Secret'] ?? '';

        if ($raw === '' || $raw === []) {
            return '';
        }

        return self::normalize($raw);
    }
}
