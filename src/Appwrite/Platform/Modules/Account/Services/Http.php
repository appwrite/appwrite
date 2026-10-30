<?php

namespace Appwrite\Platform\Modules\Account\Services;

use Appwrite\Platform\Modules\Account\Http\Account\Create as CreateAccount;
use Appwrite\Platform\Modules\Account\Http\Account\Delete as DeleteAccount;
use Appwrite\Platform\Modules\Account\Http\Account\Email\Update as UpdateEmail;
use Appwrite\Platform\Modules\Account\Http\Account\Get as GetAccount;
use Appwrite\Platform\Modules\Account\Http\Account\Identities\Delete as DeleteIdentity;
use Appwrite\Platform\Modules\Account\Http\Account\Identities\XList as ListIdentities;
use Appwrite\Platform\Modules\Account\Http\Account\JWTs\Create as CreateJWT;
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
use Appwrite\Platform\Modules\Account\Http\Account\Name\Update as UpdateName;
use Appwrite\Platform\Modules\Account\Http\Account\Passkeys\Create as CreatePasskey;
use Appwrite\Platform\Modules\Account\Http\Account\Passkeys\Delete as DeletePasskey;
use Appwrite\Platform\Modules\Account\Http\Account\Passkeys\Get as GetPasskey;
use Appwrite\Platform\Modules\Account\Http\Account\Passkeys\Update as UpdatePasskey;
use Appwrite\Platform\Modules\Account\Http\Account\Passkeys\Verification\Update as UpdatePasskeyVerification;
use Appwrite\Platform\Modules\Account\Http\Account\Passkeys\XList as ListPasskeys;
use Appwrite\Platform\Modules\Account\Http\Account\Password\Update as UpdatePassword;
use Appwrite\Platform\Modules\Account\Http\Account\Phone\Update as UpdatePhone;
use Appwrite\Platform\Modules\Account\Http\Account\Prefs\Get as GetPrefs;
use Appwrite\Platform\Modules\Account\Http\Account\Prefs\Update as UpdatePrefs;
use Appwrite\Platform\Modules\Account\Http\Account\Recovery\Create as CreateRecovery;
use Appwrite\Platform\Modules\Account\Http\Account\Recovery\OTP\Create as CreateRecoveryOTP;
use Appwrite\Platform\Modules\Account\Http\Account\Recovery\OTP\Update as UpdateRecoveryOTP;
use Appwrite\Platform\Modules\Account\Http\Account\Recovery\Update as UpdateRecovery;
use Appwrite\Platform\Modules\Account\Http\Account\Sessions\Anonymous\Create as CreateAnonymousSession;
use Appwrite\Platform\Modules\Account\Http\Account\Sessions\Bulk\Delete as DeleteSessions;
use Appwrite\Platform\Modules\Account\Http\Account\Sessions\Delete as DeleteSession;
use Appwrite\Platform\Modules\Account\Http\Account\Sessions\Email\Create as CreateEmailPasswordSession;
use Appwrite\Platform\Modules\Account\Http\Account\Sessions\Get as GetSession;
use Appwrite\Platform\Modules\Account\Http\Account\Sessions\IdToken\Create as CreateIdTokenSession;
use Appwrite\Platform\Modules\Account\Http\Account\Sessions\MagicURL\Update as UpdateMagicURLSession;
use Appwrite\Platform\Modules\Account\Http\Account\Sessions\OAuth2\Callback\Create as CreateOAuth2Callback;
use Appwrite\Platform\Modules\Account\Http\Account\Sessions\OAuth2\Callback\Get as GetOAuth2Callback;
use Appwrite\Platform\Modules\Account\Http\Account\Sessions\OAuth2\Get as CreateOAuth2Session;
use Appwrite\Platform\Modules\Account\Http\Account\Sessions\OAuth2\Redirect\Get as GetOAuth2Redirect;
use Appwrite\Platform\Modules\Account\Http\Account\Sessions\Phone\Update as UpdatePhoneSession;
use Appwrite\Platform\Modules\Account\Http\Account\Sessions\Token\Create as CreateSession;
use Appwrite\Platform\Modules\Account\Http\Account\Sessions\Update as UpdateSession;
use Appwrite\Platform\Modules\Account\Http\Account\Sessions\XList as ListSessions;
use Appwrite\Platform\Modules\Account\Http\Account\Status\Update as UpdateStatus;
use Appwrite\Platform\Modules\Account\Http\Account\Targets\Push\Create as CreatePushTarget;
use Appwrite\Platform\Modules\Account\Http\Account\Targets\Push\Delete as DeletePushTarget;
use Appwrite\Platform\Modules\Account\Http\Account\Targets\Push\Update as UpdatePushTarget;
use Appwrite\Platform\Modules\Account\Http\Account\Tokens\Email\Create as CreateEmailToken;
use Appwrite\Platform\Modules\Account\Http\Account\Tokens\MagicURL\Create as CreateMagicURLToken;
use Appwrite\Platform\Modules\Account\Http\Account\Tokens\OAuth2\Get as CreateOAuth2Token;
use Appwrite\Platform\Modules\Account\Http\Account\Tokens\Passkey\Create as CreatePasskeyToken;
use Appwrite\Platform\Modules\Account\Http\Account\Tokens\Passkey\Update as UpdatePasskeyToken;
use Appwrite\Platform\Modules\Account\Http\Account\Tokens\Phone\Create as CreatePhoneToken;
use Appwrite\Platform\Modules\Account\Http\Account\Verifications\Email\Create as CreateEmailVerification;
use Appwrite\Platform\Modules\Account\Http\Account\Verifications\Email\OTP\Create as CreateEmailVerificationOTP;
use Appwrite\Platform\Modules\Account\Http\Account\Verifications\Email\OTP\Update as UpdateEmailVerificationOTP;
use Appwrite\Platform\Modules\Account\Http\Account\Verifications\Email\Update as UpdateEmailVerification;
use Appwrite\Platform\Modules\Account\Http\Account\Verifications\Phone\Create as CreatePhoneVerification;
use Appwrite\Platform\Modules\Account\Http\Account\Verifications\Phone\Update as UpdatePhoneVerification;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;
        $this
            ->addAction(CreateAccount::getName(), new CreateAccount())
            ->addAction(GetAccount::getName(), new GetAccount())
            ->addAction(DeleteAccount::getName(), new DeleteAccount())
            ->addAction(ListSessions::getName(), new ListSessions())
            ->addAction(DeleteSessions::getName(), new DeleteSessions())
            ->addAction(GetSession::getName(), new GetSession())
            ->addAction(DeleteSession::getName(), new DeleteSession())
            ->addAction(UpdateSession::getName(), new UpdateSession())
            ->addAction(CreateEmailPasswordSession::getName(), new CreateEmailPasswordSession())
            ->addAction(CreateAnonymousSession::getName(), new CreateAnonymousSession())
            ->addAction(CreateSession::getName(), new CreateSession())
            ->addAction(CreateOAuth2Session::getName(), new CreateOAuth2Session())
            ->addAction(GetOAuth2Callback::getName(), new GetOAuth2Callback())
            ->addAction(CreateOAuth2Callback::getName(), new CreateOAuth2Callback())
            ->addAction(GetOAuth2Redirect::getName(), new GetOAuth2Redirect())
            ->addAction(CreateOAuth2Token::getName(), new CreateOAuth2Token())
            ->addAction(CreateMagicURLToken::getName(), new CreateMagicURLToken())
            ->addAction(CreateEmailToken::getName(), new CreateEmailToken())
            ->addAction(UpdateMagicURLSession::getName(), new UpdateMagicURLSession())
            ->addAction(UpdatePhoneSession::getName(), new UpdatePhoneSession())
            ->addAction(CreatePhoneToken::getName(), new CreatePhoneToken())
            ->addAction(CreateJWT::getName(), new CreateJWT())
            ->addAction(GetPrefs::getName(), new GetPrefs())
            ->addAction(UpdateName::getName(), new UpdateName())
            ->addAction(UpdatePassword::getName(), new UpdatePassword())
            ->addAction(UpdateEmail::getName(), new UpdateEmail())
            ->addAction(UpdatePhone::getName(), new UpdatePhone())
            ->addAction(UpdatePrefs::getName(), new UpdatePrefs())
            ->addAction(UpdateStatus::getName(), new UpdateStatus())
            ->addAction(CreateRecovery::getName(), new CreateRecovery())
            ->addAction(UpdateRecovery::getName(), new UpdateRecovery())
            ->addAction(CreateRecoveryOTP::getName(), new CreateRecoveryOTP())
            ->addAction(UpdateRecoveryOTP::getName(), new UpdateRecoveryOTP())
            ->addAction(CreateEmailVerification::getName(), new CreateEmailVerification())
            ->addAction(UpdateEmailVerification::getName(), new UpdateEmailVerification())
            ->addAction(CreatePhoneVerification::getName(), new CreatePhoneVerification())
            ->addAction(UpdatePhoneVerification::getName(), new UpdatePhoneVerification())
            ->addAction(CreatePushTarget::getName(), new CreatePushTarget())
            ->addAction(UpdatePushTarget::getName(), new UpdatePushTarget())
            ->addAction(DeletePushTarget::getName(), new DeletePushTarget())
            ->addAction(ListIdentities::getName(), new ListIdentities())
            ->addAction(DeleteIdentity::getName(), new DeleteIdentity())
            ->addAction(CreateEmailVerificationOTP::getName(), new CreateEmailVerificationOTP())
            ->addAction(UpdateEmailVerificationOTP::getName(), new UpdateEmailVerificationOTP())
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
            ->addAction(ListMemberships::getName(), new ListMemberships())
            ->addAction(CreatePasskeyToken::getName(), new CreatePasskeyToken())
            ->addAction(UpdatePasskeyToken::getName(), new UpdatePasskeyToken());
    }
}
