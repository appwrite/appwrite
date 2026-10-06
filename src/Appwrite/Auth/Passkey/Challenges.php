<?php

namespace Appwrite\Auth\Passkey;

use Appwrite\Extend\Exception;
use Utopia\Auth\Passkeys\Challenge;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

/**
 * Stored passkey ceremonies: each is bound to its purpose, owner, relying party configuration and
 * any extra context, expires with the ceremony, and can be consumed exactly once.
 */
class Challenges
{
    private const int VERSION = 1;

    public function __construct(
        private readonly Database $dbForProject,
        private readonly Authorization $authorization,
    ) {
    }

    /**
     * @param array<string, string> $binding context the completing request must match, such as a session ID
     */
    public function issue(string $id, string $type, Ceremony $ceremony, Challenge $challenge, ?Document $user = null, array $binding = []): Document
    {
        return $this->authorization->skip(fn () => $this->dbForProject->createDocument('challenges', new Document([
            '$id' => $id,
            'userId' => $user?->getId(),
            'userInternalId' => $user?->getSequence(),
            'type' => $type,
            'expire' => DateTime::addSeconds(new \DateTime(), Ceremony::TIMEOUT),
            'passkey' => [
                'version' => self::VERSION,
                'relyingParty' => $ceremony->relyingParty->getFingerprint(),
                'binding' => $binding,
                'state' => $challenge->state,
            ],
        ])));
    }

    /**
     * Delete the challenge and return its ceremony state. Of concurrent calls, only one succeeds.
     *
     * @param array<string, string> $binding
     * @throws Exception USER_INVALID_TOKEN when missing, expired, used, or issued for another purpose, owner or configuration
     */
    public function consume(string $challengeId, string $type, Ceremony $ceremony, ?Document $user = null, array $binding = []): string
    {
        $challenge = $this->authorization->skip(fn () => $this->dbForProject->getDocument('challenges', $challengeId));
        $passkey = $challenge->getAttribute('passkey', []);

        if (
            $challenge->isEmpty()
            || $challenge->getAttribute('type') !== $type
            || $challenge->getAttribute('userInternalId') !== $user?->getSequence()
            || $challenge->getAttribute('expire') < DateTime::formatTz(DateTime::now())
            || ($passkey['version'] ?? null) !== self::VERSION
            || ($passkey['binding'] ?? null) !== $binding
            || ($passkey['relyingParty'] ?? null) !== $ceremony->relyingParty->getFingerprint()
        ) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        if (!$this->authorization->skip(fn () => $this->dbForProject->deleteDocument('challenges', $challengeId))) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        return $passkey['state'];
    }
}
