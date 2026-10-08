<?php

namespace Appwrite\Auth\Passkey;

use Utopia\Auth\Passkeys\Ceremony as Base;
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
     * Returns null until the project has both an RP ID and at least one origin, so passkeys fail closed.
     */
    public static function fromProject(Document $project): ?self
    {
        $auths = $project->getAttribute('auths', []);
        $id = $auths['passkeyRpId'] ?? '';
        $origins = $auths['passkeyOrigins'] ?? [];

        if ($id === '' || empty($origins)) {
            return null;
        }

        return new self(new RelyingParty($id, $project->getAttribute('name', ''), $origins));
    }
}
