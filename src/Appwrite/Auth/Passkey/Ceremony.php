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
     * Origins come from the passkey policy, or else from the project's web platforms on the RP ID. A request from
     * localhost gets a localhost relying party when localhost is a web platform, so local development works next
     * to the production domain without touching the policy.
     */
    public static function fromProject(Document $project, string $origin = ''): ?self
    {
        $auths = $project->getAttribute('auths', []);
        $id = $auths['passkeyRpId'] ?? '';
        $name = $project->getAttribute('name', '');
        $hostnames = self::getHostnames($project);

        $host = \parse_url($origin, PHP_URL_HOST) ?: '';
        if ($host === Origin::LOCALHOST && $id !== Origin::LOCALHOST && \in_array(Origin::LOCALHOST, $hostnames, true)) {
            return new self(new RelyingParty(Origin::LOCALHOST, $name, self::getOrigins(Origin::LOCALHOST, $hostnames)));
        }

        if ($id === '') {
            return null;
        }

        $origins = $auths['passkeyOrigins'] ?? [];
        if (empty($origins)) {
            $origins = self::getOrigins($id, $hostnames);
        }

        if (empty($origins)) {
            return null;
        }

        return new self(new RelyingParty($id, $name, $origins));
    }

    /**
     * Origins of the web platforms on the RP ID or one of its subdomains. Wildcard platforms name no single origin.
     *
     * @param array<string> $hostnames
     * @return array<string>
     */
    public static function getOrigins(string $rpId, array $hostnames): array
    {
        $origins = [];
        foreach ($hostnames as $hostname) {
            if ($rpId === Origin::LOCALHOST && $hostname === Origin::LOCALHOST) {
                \array_push($origins, 'http://' . Origin::LOCALHOST, 'https://' . Origin::LOCALHOST);
            } elseif ($rpId !== Origin::LOCALHOST && ($hostname === $rpId || \str_ends_with($hostname, '.' . $rpId)) && !\str_contains($hostname, '*')) {
                $origins[] = 'https://' . $hostname;
            }
        }

        return \array_values(\array_unique($origins));
    }

    /**
     * @return array<string>
     */
    private static function getHostnames(Document $project): array
    {
        $hostnames = [];
        foreach ($project->getAttribute('platforms', []) as $platform) {
            if (Platform::mapDeprecatedType(\strtolower($platform['type'] ?? '')) === Platform::TYPE_WEB && !empty($platform['hostname'])) {
                $hostnames[] = \strtolower($platform['hostname']);
            }
        }

        return $hostnames;
    }
}
