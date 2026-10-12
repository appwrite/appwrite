<?php

namespace Appwrite\Platform\Modules\Account\Services;

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
            ->addAction(UpdateMfa::getName(), new UpdateMfa())
            ->addAction(ListFactors::getName(), new ListFactors())
            ->addAction(CreateAuthenticator::getName(), new CreateAuthenticator())
            ->addAction(UpdateAuthenticator::getName(), new UpdateAuthenticator())
            ->addAction(DeleteAuthenticator::getName(), new DeleteAuthenticator())
            ->addAction(CreateRecoveryCodes::getName(), new CreateRecoveryCodes())
            ->addAction(UpdateRecoveryCodes::getName(), new UpdateRecoveryCodes())
            ->addAction(GetRecoveryCodes::getName(), new GetRecoveryCodes())
            ->addAction(CreateChallenge::getName(), new CreateChallenge())
            ->addAction(UpdateChallenge::getName(), new UpdateChallenge())
            ->addAction(CreateIdTokenSession::getName(), new CreateIdTokenSession())
            ->addAction(CreatePasskey::getName(), new CreatePasskey())
            ->addAction(UpdatePasskeyVerification::getName(), new UpdatePasskeyVerification())
            ->addAction(ListPasskeys::getName(), new ListPasskeys())
            ->addAction(GetPasskey::getName(), new GetPasskey())
            ->addAction(DeletePasskey::getName(), new DeletePasskey())
            ->addAction(UpdatePasskey::getName(), new UpdatePasskey())
            ->addAction(CreatePasskeyToken::getName(), new CreatePasskeyToken())
            ->addAction(UpdatePasskeyToken::getName(), new UpdatePasskeyToken());
    }
}
