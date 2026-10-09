<?php

namespace Appwrite\Auth\Passkey;

use Appwrite\Network\Platform;
use Utopia\Auth\Passkeys\Ceremony as Base;
use Utopia\Auth\Passkeys\Origin;
use Utopia\Auth\Passkeys\RelyingParty;
use Utopia\Database\Document;

/**
 * Passkey ceremonies for a project, plus how passkeys and their challenges are stored.
 */
class Ceremony extends Base
{
    public const string TYPE = 'passkey';
    public const string TYPE_REGISTRATION = 'passkeyRegistration';
    public const string TYPE_AUTHENTICATION = 'passkeyAuthentication';

    /**
     * The relying party for a ceremony started from the given origin, or null when passkeys cannot work there.
     *
     * Origins are the project's platforms on the RP ID. A request from localhost gets a localhost relying party
     * when localhost is a web platform, so local development works next to the production domain.
     */
    public static function fromProject(Document $project, string $origin = ''): ?self
    {
        $auths = $project->getAttribute('auths', []);
        $id = $auths['passkeyRpId'] ?? '';
        $name = $project->getAttribute('name', '');

        // The console project has no platforms, so its origins come from configuration
        if ($project->getId() === 'console') {
            $origins = $auths['passkeyOrigins'] ?? [];

            return $id === '' || empty($origins) ? null : new self(new RelyingParty($id, $name, $origins));
        }

        $platforms = $project->getAttribute('platforms', []);
        $host = \parse_url($origin, PHP_URL_HOST) ?: '';
        if ($host === Origin::LOCALHOST && \in_array(Origin::LOCALHOST, self::getHostnames($platforms), true)) {
            $id = Origin::LOCALHOST;
        }

        $origins = $id === '' ? [] : self::getRelyingPartyOrigins($id, $platforms);

        return empty($origins) ? null : new self(new RelyingParty($id, $name, $origins));
    }

    /**
     * Every origin passkeys work on for the project: the relying party's, plus localhost for local development.
     *
     * @return array<string>
     */
    public static function getOrigins(Document $project): array
    {
        $auths = $project->getAttribute('auths', []);
        if ($project->getId() === 'console') {
            return $auths['passkeyOrigins'] ?? [];
        }

        $id = $auths['passkeyRpId'] ?? '';
        $platforms = $project->getAttribute('platforms', []);
        $origins = $id === '' ? [] : self::getRelyingPartyOrigins($id, $platforms);
        if ($id !== Origin::LOCALHOST) {
            \array_push($origins, ...self::getRelyingPartyOrigins(Origin::LOCALHOST, $platforms));
        }

        return $origins;
    }

    /**
     * Origins of the web platforms on the RP ID or one of its subdomains, keeping wildcards such as
     * `https://*.example.com`. Apple apps sign in from the RP ID itself once the domain lists them as associated.
     *
     * @param array<array<string, mixed>|Document> $platforms
     * @return array<string>
     */
    private static function getRelyingPartyOrigins(string $rpId, array $platforms): array
    {
        if ($rpId === Origin::LOCALHOST) {
            return \in_array(Origin::LOCALHOST, self::getHostnames($platforms), true)
                ? ['http://' . Origin::LOCALHOST, 'https://' . Origin::LOCALHOST]
                : [];
        }

        $origins = [];
        foreach ($platforms as $platform) {
            if (Platform::mapDeprecatedType(\strtolower($platform['type'] ?? '')) === Platform::TYPE_APPLE) {
                $origins[] = 'https://' . $rpId;
            }
        }

        foreach (self::getHostnames($platforms) as $hostname) {
            $domain = \str_starts_with($hostname, '*.') ? \substr($hostname, 2) : $hostname;
            if (!\str_contains($domain, '*') && ($domain === $rpId || \str_ends_with($domain, '.' . $rpId))) {
                $origins[] = 'https://' . $hostname;
            }
        }

        return \array_values(\array_unique($origins));
    }

    /**
     * @param array<array<string, mixed>|Document> $platforms
     * @return array<string>
     */
    private static function getHostnames(array $platforms): array
    {
        $hostnames = [];
        foreach ($platforms as $platform) {
            if (Platform::mapDeprecatedType(\strtolower($platform['type'] ?? '')) === Platform::TYPE_WEB && !empty($platform['hostname'])) {
                $hostnames[] = \strtolower($platform['hostname']);
            }
        }

        return $hostnames;
    }
}
