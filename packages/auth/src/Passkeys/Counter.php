<?php

declare(strict_types=1);

namespace Utopia\Auth\Passkeys;

use Webauthn\Counter\CounterChecker;
use Webauthn\CredentialRecord;
use Webauthn\Exception\CounterException;

/**
 * Synced passkeys share one key across devices, so their counters cannot be trusted to increase.
 * Device-bound credentials keep the strict check, except authenticators that never implement a counter.
 */
class Counter implements CounterChecker
{
    public function check(CredentialRecord $credentialRecord, int $currentCounter): void
    {
        // Authenticators without a counter always report zero, which is allowed
        if ($currentCounter === 0 && $credentialRecord->counter === 0) {
            return;
        }

        if ($currentCounter > $credentialRecord->counter || $credentialRecord->backupEligible === true) {
            return;
        }

        throw CounterException::create($currentCounter, $credentialRecord->counter, 'Invalid counter.');
    }
}
