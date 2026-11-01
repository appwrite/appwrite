<?php

namespace Appwrite\Platform\Modules\Users\Services;

use Appwrite\Platform\Modules\Users\Http\Users\Argon2\Create as CreateArgon2User;
use Appwrite\Platform\Modules\Users\Http\Users\Bcrypt\Create as CreateBcryptUser;
use Appwrite\Platform\Modules\Users\Http\Users\Create as CreateUser;
use Appwrite\Platform\Modules\Users\Http\Users\Delete as DeleteUser;
use Appwrite\Platform\Modules\Users\Http\Users\Email\Update as UpdateEmail;
use Appwrite\Platform\Modules\Users\Http\Users\Get as GetUser;
use Appwrite\Platform\Modules\Users\Http\Users\Identities\Delete as DeleteIdentity;
use Appwrite\Platform\Modules\Users\Http\Users\Identities\XList as ListIdentities;
use Appwrite\Platform\Modules\Users\Http\Users\Impersonator\Update as UpdateImpersonator;
use Appwrite\Platform\Modules\Users\Http\Users\JWTs\Create as CreateJWT;
use Appwrite\Platform\Modules\Users\Http\Users\Labels\Update as UpdateLabels;
use Appwrite\Platform\Modules\Users\Http\Users\MD5\Create as CreateMD5User;
use Appwrite\Platform\Modules\Users\Http\Users\Memberships\XList as ListMemberships;
use Appwrite\Platform\Modules\Users\Http\Users\MFA\Authenticators\Delete as DeleteAuthenticator;
use Appwrite\Platform\Modules\Users\Http\Users\MFA\Challenges\Get as GetMFAChallenge;
use Appwrite\Platform\Modules\Users\Http\Users\MFA\Factors\XList as ListFactors;
use Appwrite\Platform\Modules\Users\Http\Users\MFA\RecoveryCodes\Create as CreateRecoveryCodes;
use Appwrite\Platform\Modules\Users\Http\Users\MFA\RecoveryCodes\Get as GetRecoveryCodes;
use Appwrite\Platform\Modules\Users\Http\Users\MFA\RecoveryCodes\Update as UpdateRecoveryCodes;
use Appwrite\Platform\Modules\Users\Http\Users\MFA\Update as UpdateMFA;
use Appwrite\Platform\Modules\Users\Http\Users\Name\Update as UpdateName;
use Appwrite\Platform\Modules\Users\Http\Users\Passkeys\Delete as DeletePasskey;
use Appwrite\Platform\Modules\Users\Http\Users\Passkeys\Get as GetPasskey;
use Appwrite\Platform\Modules\Users\Http\Users\Passkeys\Update as UpdatePasskey;
use Appwrite\Platform\Modules\Users\Http\Users\Passkeys\XList as ListPasskeys;
use Appwrite\Platform\Modules\Users\Http\Users\Password\Update as UpdatePassword;
use Appwrite\Platform\Modules\Users\Http\Users\Phone\Update as UpdatePhone;
use Appwrite\Platform\Modules\Users\Http\Users\PHPass\Create as CreatePHPassUser;
use Appwrite\Platform\Modules\Users\Http\Users\Prefs\Get as GetPrefs;
use Appwrite\Platform\Modules\Users\Http\Users\Prefs\Update as UpdatePrefs;
use Appwrite\Platform\Modules\Users\Http\Users\Scrypt\Create as CreateScryptUser;
use Appwrite\Platform\Modules\Users\Http\Users\Scrypt\Modified\Create as CreateScryptModifiedUser;
use Appwrite\Platform\Modules\Users\Http\Users\Sessions\Bulk\Delete as DeleteSessions;
use Appwrite\Platform\Modules\Users\Http\Users\Sessions\Create as CreateSession;
use Appwrite\Platform\Modules\Users\Http\Users\Sessions\Delete as DeleteSession;
use Appwrite\Platform\Modules\Users\Http\Users\Sessions\XList as ListSessions;
use Appwrite\Platform\Modules\Users\Http\Users\SHA\Create as CreateSHAUser;
use Appwrite\Platform\Modules\Users\Http\Users\Status\Update as UpdateStatus;
use Appwrite\Platform\Modules\Users\Http\Users\Targets\Create as CreateTarget;
use Appwrite\Platform\Modules\Users\Http\Users\Targets\Delete as DeleteTarget;
use Appwrite\Platform\Modules\Users\Http\Users\Targets\Get as GetTarget;
use Appwrite\Platform\Modules\Users\Http\Users\Targets\Update as UpdateTarget;
use Appwrite\Platform\Modules\Users\Http\Users\Targets\XList as ListTargets;
use Appwrite\Platform\Modules\Users\Http\Users\Tokens\Create as CreateToken;
use Appwrite\Platform\Modules\Users\Http\Users\Verification\Phone\Update as UpdatePhoneVerification;
use Appwrite\Platform\Modules\Users\Http\Users\Verification\Update as UpdateEmailVerification;
use Appwrite\Platform\Modules\Users\Http\Users\XList as ListUsers;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        // Users
        $this->addAction(CreateUser::getName(), CreateUser::class);
        $this->addAction(GetUser::getName(), GetUser::class);
        $this->addAction(ListUsers::getName(), ListUsers::class);
        $this->addAction(DeleteUser::getName(), DeleteUser::class);

        // Users with pre-hashed passwords
        $this->addAction(CreateBcryptUser::getName(), CreateBcryptUser::class);
        $this->addAction(CreateMD5User::getName(), CreateMD5User::class);
        $this->addAction(CreateArgon2User::getName(), CreateArgon2User::class);
        $this->addAction(CreateSHAUser::getName(), CreateSHAUser::class);
        $this->addAction(CreatePHPassUser::getName(), CreatePHPassUser::class);
        $this->addAction(CreateScryptUser::getName(), CreateScryptUser::class);
        $this->addAction(CreateScryptModifiedUser::getName(), CreateScryptModifiedUser::class);

        // Properties
        $this->addAction(UpdateStatus::getName(), UpdateStatus::class);
        $this->addAction(UpdateLabels::getName(), UpdateLabels::class);
        $this->addAction(UpdateImpersonator::getName(), UpdateImpersonator::class);
        $this->addAction(UpdateName::getName(), UpdateName::class);
        $this->addAction(UpdatePassword::getName(), UpdatePassword::class);
        $this->addAction(UpdateEmail::getName(), UpdateEmail::class);
        $this->addAction(UpdatePhone::getName(), UpdatePhone::class);
        $this->addAction(UpdateEmailVerification::getName(), UpdateEmailVerification::class);
        $this->addAction(UpdatePhoneVerification::getName(), UpdatePhoneVerification::class);

        // Preferences
        $this->addAction(GetPrefs::getName(), GetPrefs::class);
        $this->addAction(UpdatePrefs::getName(), UpdatePrefs::class);

        // Targets
        $this->addAction(CreateTarget::getName(), CreateTarget::class);
        $this->addAction(GetTarget::getName(), GetTarget::class);
        $this->addAction(ListTargets::getName(), ListTargets::class);
        $this->addAction(UpdateTarget::getName(), UpdateTarget::class);
        $this->addAction(DeleteTarget::getName(), DeleteTarget::class);

        // Sessions
        $this->addAction(CreateSession::getName(), CreateSession::class);
        $this->addAction(ListSessions::getName(), ListSessions::class);
        $this->addAction(DeleteSession::getName(), DeleteSession::class);
        $this->addAction(DeleteSessions::getName(), DeleteSessions::class);

        // Tokens
        $this->addAction(CreateToken::getName(), CreateToken::class);
        $this->addAction(CreateJWT::getName(), CreateJWT::class);

        // Memberships
        $this->addAction(ListMemberships::getName(), ListMemberships::class);

        // Identities
        $this->addAction(ListIdentities::getName(), ListIdentities::class);
        $this->addAction(DeleteIdentity::getName(), DeleteIdentity::class);

        // MFA
        $this->addAction(UpdateMFA::getName(), UpdateMFA::class);
        $this->addAction(ListFactors::getName(), ListFactors::class);
        $this->addAction(GetMFAChallenge::getName(), GetMFAChallenge::class);
        $this->addAction(GetRecoveryCodes::getName(), GetRecoveryCodes::class);
        $this->addAction(CreateRecoveryCodes::getName(), CreateRecoveryCodes::class);
        $this->addAction(UpdateRecoveryCodes::getName(), UpdateRecoveryCodes::class);
        $this->addAction(DeleteAuthenticator::getName(), DeleteAuthenticator::class);
        $this->addAction(ListPasskeys::getName(), ListPasskeys::class);
        $this->addAction(GetPasskey::getName(), GetPasskey::class);
        $this->addAction(UpdatePasskey::getName(), UpdatePasskey::class);
        $this->addAction(DeletePasskey::getName(), DeletePasskey::class);
    }
}
