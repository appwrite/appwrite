<?php

namespace Appwrite\Platform\Modules\Account\Services;

use Appwrite\Platform\Modules\Account\Http\Account\Memberships\XList as ListMemberships;
use Appwrite\Platform\Modules\Account\Http\Account\MFA\Authenticators\Create as CreateAuthenticator;
use Appwrite\Platform\Modules\Account\Http\Account\MFA\Authenticators\Delete as DeleteAuthenticator;
use Appwrite\Platform\Modules\Account\Http\Account\MFA\Authenticators\Update as UpdateAuthenticator;
use Appwrite\Platform\Modules\Account\Http\Account\MFA\Challenges\Create as CreateChallenge;
use Appwrite\Platform\Modules\Account\Http\Account\MFA\Challenges\Update as UpdateChallenge;
use Appwrite\Platform\Modules\Account\Http\Account\MFA\Factors\XList as ListFactors;
use Appwrite\Platform\Modules\Account\Http\Account\MFA\RecoveryCodes\Create as CreateRecoveryCodes;
use Appwrite\Platform\Modules\Account\Http\Account\MFA\RecoveryCodes\Get as GetRecoveryCodes;
use Appwrite\Platform\Modules\Account\Http\Account\MFA\RecoveryCodes\Update as UpdateRecoveryCodes;
use Appwrite\Platform\Modules\Account\Http\Account\MFA\Update as UpdateMfa;
use Appwrite\Platform\Modules\Account\Http\Account\Passkeys\Create as CreatePasskey;
use Appwrite\Platform\Modules\Account\Http\Account\Passkeys\Delete as DeletePasskey;
use Appwrite\Platform\Modules\Account\Http\Account\Passkeys\Get as GetPasskey;
use Appwrite\Platform\Modules\Account\Http\Account\Passkeys\Update as UpdatePasskey;
use Appwrite\Platform\Modules\Account\Http\Account\Passkeys\Verification\Update as UpdatePasskeyVerification;
use Appwrite\Platform\Modules\Account\Http\Account\Passkeys\XList as ListPasskeys;
use Appwrite\Platform\Modules\Account\Http\Account\Sessions\IdToken\Create as CreateIdTokenSession;
use Appwrite\Platform\Modules\Account\Http\Account\Tokens\Passkey\Create as CreatePasskeyToken;
use Appwrite\Platform\Modules\Account\Http\Account\Tokens\Passkey\Update as UpdatePasskeyToken;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;
        $this
            ->addAction(UpdateMfa::getName(), UpdateMfa::class)
            ->addAction(ListFactors::getName(), ListFactors::class)
            ->addAction(CreateAuthenticator::getName(), CreateAuthenticator::class)
            ->addAction(UpdateAuthenticator::getName(), UpdateAuthenticator::class)
            ->addAction(DeleteAuthenticator::getName(), DeleteAuthenticator::class)
            ->addAction(CreateRecoveryCodes::getName(), CreateRecoveryCodes::class)
            ->addAction(UpdateRecoveryCodes::getName(), UpdateRecoveryCodes::class)
            ->addAction(GetRecoveryCodes::getName(), GetRecoveryCodes::class)
            ->addAction(CreateChallenge::getName(), CreateChallenge::class)
            ->addAction(UpdateChallenge::getName(), UpdateChallenge::class)
            ->addAction(CreateIdTokenSession::getName(), CreateIdTokenSession::class)
            ->addAction(CreatePasskey::getName(), CreatePasskey::class)
            ->addAction(UpdatePasskeyVerification::getName(), UpdatePasskeyVerification::class)
            ->addAction(ListPasskeys::getName(), ListPasskeys::class)
            ->addAction(GetPasskey::getName(), GetPasskey::class)
            ->addAction(DeletePasskey::getName(), DeletePasskey::class)
            ->addAction(UpdatePasskey::getName(), UpdatePasskey::class)
            ->addAction(ListMemberships::getName(), ListMemberships::class)
            ->addAction(CreatePasskeyToken::getName(), CreatePasskeyToken::class)
            ->addAction(UpdatePasskeyToken::getName(), UpdatePasskeyToken::class);
    }
}
