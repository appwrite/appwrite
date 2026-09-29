<?php

namespace Appwrite\Auth\Passkey;

use Utopia\Database\Document;

readonly class RelyingParty
{
    /**
     * @param array<string> $origins
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $origins,
    ) {
    }

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

        return new self($id, $project->getAttribute('name', ''), $origins);
    }

    public function getFingerprint(): string
    {
        $origins = $this->origins;
        \sort($origins);

        return \hash('sha256', $this->id . "\n" . \implode("\n", $origins));
    }
}
