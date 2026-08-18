<?php

namespace Appwrite\Vcs;

use Utopia\System\System;
use Utopia\VCS\Adapter\Git;

/**
 * Resolves where a deployment's source tarball comes from.
 *
 * Most providers hand out a short-lived presigned archive URL. A provider
 * without archive downloads (Origin serves content over Git HTTPS only) is
 * answered with an Appwrite URL instead: a signed, expiring link to
 * GET /v1/vcs/archives, which packages the repository server-side.
 */
class SourceArchive
{
    /**
     * How long a self-served archive link stays valid. Generous next to the
     * providers' own presigned URLs, because the jobs-service may retry the
     * source fetch.
     */
    public const TTL = 3600;

    /**
     * @param array<string, mixed> $platform
     * @return array{string, array<string, string>} The source URL and the headers to fetch it with
     */
    public static function presign(Git $vcs, string $providerInstallationId, string $owner, string $repository, string $ref, array $platform): array
    {
        if ($vcs->supportsRepositoryArchives()) {
            return [
                $vcs->getRepositoryPresignedUrl($owner, $repository, $ref),
                $vcs->getRepositoryPresignedUrlHeaders(),
            ];
        }

        $expires = \time() + self::TTL;

        $params = [
            'provider' => $vcs->getName(),
            'installation' => $providerInstallationId,
            'owner' => $owner,
            'repository' => $repository,
            'ref' => $ref,
            'expires' => $expires,
            'signature' => self::signature($vcs->getName(), $providerInstallationId, $owner, $repository, $ref, $expires),
        ];

        // The jobs-service reaches Appwrite over the internal Docker network
        // when configured, the same way its presigned callback URLs do.
        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';
        $endpoint = \rtrim(System::getEnv('_APP_JOBS_ENDPOINT', $protocol . '://' . ($platform['apiHostname'] ?? '')), '/');

        return [$endpoint . '/v1/vcs/archives?' . \http_build_query($params), []];
    }

    public static function signature(string $provider, string $providerInstallationId, string $owner, string $repository, string $ref, int $expires): string
    {
        return \hash_hmac(
            'sha256',
            \implode("\n", [$provider, $providerInstallationId, $owner, $repository, $ref, $expires]),
            System::getEnv('_APP_OPENSSL_KEY_V1', '')
        );
    }
}
