<?php

namespace Appwrite\Auth\Passkey;

use Webauthn\Counter\CounterChecker;
use Webauthn\CredentialRecord;
use Webauthn\Exception\CounterException;

/**
 * Synced passkeys share one key across devices, so their counters cannot be trusted to increase.
 * Device-bound credentials keep the strict check.
 */
class Counter implements CounterChecker
{
    public function check(CredentialRecord $credentialRecord, int $currentCounter): void
    {
        if ($currentCounter > $credentialRecord->counter || $credentialRecord->backupEligible === true) {
            return;
        }

        throw CounterException::create($currentCounter, $credentialRecord->counter, 'Invalid counter.');
    }
}
