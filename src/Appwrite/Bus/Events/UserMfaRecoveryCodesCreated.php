<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when a user's MFA recovery codes are created
 * (`users.[userId].create.mfa.recovery-codes`).
 */
class UserMfaRecoveryCodesCreated extends ResourceEvent
{
    public function __construct(string $userId, Document $recoveryCodes, ?Document $project = null, ?Document $actor = null)
    {
        parent::__construct(
            event: 'users.[userId].create.mfa.recovery-codes',
            params: ['userId' => $userId],
            document: $recoveryCodes,
            model: Response::MODEL_MFA_RECOVERY_CODES,
            project: $project,
            user: $actor,
        );
    }
}
