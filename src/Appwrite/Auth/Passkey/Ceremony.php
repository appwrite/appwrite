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
     * Origins come from the passkey policy, or else from the project's platforms on the RP ID. A request from
     * localhost gets a localhost relying party when localhost is a web platform, so local development works next
     * to the production domain without touching the policy.
     */
    public static function fromProject(Document $project, string $origin = ''): ?self
    {
        $auths = $project->getAttribute('auths', []);
        $id = $auths['passkeyRpId'] ?? '';
        $name = $project->getAttribute('name', '');
        $platforms = $project->getAttribute('platforms', []);
        $hostnames = self::getHostnames($platforms);

        $host = \parse_url($origin, PHP_URL_HOST) ?: '';
        if ($host === Origin::LOCALHOST && $id !== Origin::LOCALHOST && \in_array(Origin::LOCALHOST, $hostnames, true)) {
            return new self(new RelyingParty(Origin::LOCALHOST, $name, self::getOrigins(Origin::LOCALHOST, $platforms)));
        }

        if ($id === '') {
            return null;
        }

        $origins = $auths['passkeyOrigins'] ?? [];
        if (empty($origins)) {
            $origins = self::getOrigins($id, $platforms);
        }

        if (empty($origins)) {
            return null;
        }

        return new self(new RelyingParty($id, $name, $origins));
    }

    /**
     * Origins of the web platforms on the RP ID or one of its subdomains. Wildcard platforms name no single origin.
     * Apple apps sign in from the RP ID itself once the domain lists them as associated.
     *
     * @param array<array<string, mixed>|Document> $platforms
     * @return array<string>
     */
    private static function getOrigins(string $rpId, array $platforms): array
    {
        $origins = [];
        foreach ($platforms as $platform) {
            if ($rpId !== Origin::LOCALHOST && Platform::mapDeprecatedType(\strtolower($platform['type'] ?? '')) === Platform::TYPE_APPLE) {
                $origins[] = 'https://' . $rpId;
            }
        }

        foreach (self::getHostnames($platforms) as $hostname) {
            if ($rpId === Origin::LOCALHOST && $hostname === Origin::LOCALHOST) {
                \array_push($origins, 'http://' . Origin::LOCALHOST, 'https://' . Origin::LOCALHOST);
            } elseif ($rpId !== Origin::LOCALHOST && ($hostname === $rpId || \str_ends_with($hostname, '.' . $rpId)) && !\str_contains($hostname, '*')) {
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
