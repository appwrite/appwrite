<?php

namespace Appwrite\Platform\Modules\Account\Services;

use Appwrite\Platform\Modules\Account\Http\Account\Create as CreateAccount;
use Appwrite\Platform\Modules\Account\Http\Account\Delete as DeleteAccount;
use Appwrite\Platform\Modules\Account\Http\Account\Devices\Create as CreateDevice;
use Appwrite\Platform\Modules\Account\Http\Account\Devices\Delete as DeleteDevice;
use Appwrite\Platform\Modules\Account\Http\Account\Devices\Get as GetDevice;
use Appwrite\Platform\Modules\Account\Http\Account\Devices\Sessions\Update as UpdateDeviceSession;
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
use Appwrite\Platform\Modules\Account\Http\Account\Sessions\LDAP\Create as CreateLDAPSession;
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
            ->addAction('create', CreateAccount::class)
            ->addAction('get', GetAccount::class)
            ->addAction('delete', DeleteAccount::class)
            ->addAction('listSessions', ListSessions::class)
            ->addAction('deleteSessions', DeleteSessions::class)
            ->addAction('getSession', GetSession::class)
            ->addAction('deleteSession', DeleteSession::class)
            ->addAction('updateSession', UpdateSession::class)
            ->addAction('createDevice', CreateDevice::class)
            ->addAction('getDevice', GetDevice::class)
            ->addAction('updateDeviceSession', UpdateDeviceSession::class)
            ->addAction('deleteDevice', DeleteDevice::class)
            ->addAction('createEmailPasswordSession', CreateEmailPasswordSession::class)
            ->addAction('createAnonymousSession', CreateAnonymousSession::class)
            ->addAction('createSession', CreateSession::class)
            ->addAction('createOAuth2Session', CreateOAuth2Session::class)
            ->addAction('getOAuth2Callback', GetOAuth2Callback::class)
            ->addAction('createOAuth2Callback', CreateOAuth2Callback::class)
            ->addAction('getOAuth2Redirect', GetOAuth2Redirect::class)
            ->addAction('createOAuth2Token', CreateOAuth2Token::class)
            ->addAction('createMagicURLToken', CreateMagicURLToken::class)
            ->addAction('createEmailToken', CreateEmailToken::class)
            ->addAction('updateMagicURLSession', UpdateMagicURLSession::class)
            ->addAction('updatePhoneSession', UpdatePhoneSession::class)
            ->addAction('createPhoneToken', CreatePhoneToken::class)
            ->addAction('createJWT', CreateJWT::class)
            ->addAction('getPrefs', GetPrefs::class)
            ->addAction('updateName', UpdateName::class)
            ->addAction('updatePassword', UpdatePassword::class)
            ->addAction('updateEmail', UpdateEmail::class)
            ->addAction('updatePhone', UpdatePhone::class)
            ->addAction('updatePrefs', UpdatePrefs::class)
            ->addAction('updateStatus', UpdateStatus::class)
            ->addAction('createRecovery', CreateRecovery::class)
            ->addAction('updateRecovery', UpdateRecovery::class)
            ->addAction('createRecoveryOTP', CreateRecoveryOTP::class)
            ->addAction('updateRecoveryOTP', UpdateRecoveryOTP::class)
            ->addAction('createEmailVerification', CreateEmailVerification::class)
            ->addAction('updateEmailVerification', UpdateEmailVerification::class)
            ->addAction('createPhoneVerification', CreatePhoneVerification::class)
            ->addAction('updatePhoneVerification', UpdatePhoneVerification::class)
            ->addAction('createPushTarget', CreatePushTarget::class)
            ->addAction('updatePushTarget', UpdatePushTarget::class)
            ->addAction('deletePushTarget', DeletePushTarget::class)
            ->addAction('listIdentities', ListIdentities::class)
            ->addAction('deleteIdentity', DeleteIdentity::class)
            ->addAction('createEmailVerificationOTP', CreateEmailVerificationOTP::class)
            ->addAction('updateEmailVerificationOTP', UpdateEmailVerificationOTP::class)
            ->addAction('updateMFA', UpdateMfa::class)
            ->addAction('listMFAFactors', ListFactors::class)
            ->addAction('createMFAAuthenticator', CreateAuthenticator::class)
            ->addAction('updateMFAAuthenticator', UpdateAuthenticator::class)
            ->addAction('deleteMFAAuthenticator', DeleteAuthenticator::class)
            ->addAction('createMFARecoveryCodes', CreateRecoveryCodes::class)
            ->addAction('updateMFARecoveryCodes', UpdateRecoveryCodes::class)
            ->addAction('getMFARecoveryCodes', GetRecoveryCodes::class)
            ->addAction('createMFAChallenge', CreateChallenge::class)
            ->addAction('updateMFAChallenge', UpdateChallenge::class)
            ->addAction('createIdTokenSession', CreateIdTokenSession::class)
            ->addAction('createPasskey', CreatePasskey::class)
            ->addAction('updatePasskeyVerification', UpdatePasskeyVerification::class)
            ->addAction('listPasskeys', ListPasskeys::class)
            ->addAction('getPasskey', GetPasskey::class)
            ->addAction('deletePasskey', DeletePasskey::class)
            ->addAction('updatePasskey', UpdatePasskey::class)
            ->addAction('listMemberships', ListMemberships::class)
            ->addAction('createPasskeyToken', CreatePasskeyToken::class)
            ->addAction('updatePasskeyToken', UpdatePasskeyToken::class)
            ->addAction('createLDAPSession', CreateLDAPSession::class);
    }
}
