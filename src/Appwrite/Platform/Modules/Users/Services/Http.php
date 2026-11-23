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
        $this->addAction('createUser', CreateUser::class);
        $this->addAction('getUser', GetUser::class);
        $this->addAction('listUsers', ListUsers::class);
        $this->addAction('deleteUser', DeleteUser::class);

        // Users with pre-hashed passwords
        $this->addAction('createBcryptUser', CreateBcryptUser::class);
        $this->addAction('createMD5User', CreateMD5User::class);
        $this->addAction('createArgon2User', CreateArgon2User::class);
        $this->addAction('createSHAUser', CreateSHAUser::class);
        $this->addAction('createPHPassUser', CreatePHPassUser::class);
        $this->addAction('createScryptUser', CreateScryptUser::class);
        $this->addAction('createScryptModifiedUser', CreateScryptModifiedUser::class);

        // Properties
        $this->addAction('updateUserStatus', UpdateStatus::class);
        $this->addAction('updateUserLabels', UpdateLabels::class);
        $this->addAction('updateUserImpersonator', UpdateImpersonator::class);
        $this->addAction('updateUserName', UpdateName::class);
        $this->addAction('updateUserPassword', UpdatePassword::class);
        $this->addAction('updateUserEmail', UpdateEmail::class);
        $this->addAction('updateUserPhone', UpdatePhone::class);
        $this->addAction('updateUserEmailVerification', UpdateEmailVerification::class);
        $this->addAction('updateUserPhoneVerification', UpdatePhoneVerification::class);

        // Preferences
        $this->addAction('getUserPrefs', GetPrefs::class);
        $this->addAction('updateUserPrefs', UpdatePrefs::class);

        // Targets
        $this->addAction('createUserTarget', CreateTarget::class);
        $this->addAction('getUserTarget', GetTarget::class);
        $this->addAction('listUserTargets', ListTargets::class);
        $this->addAction('updateUserTarget', UpdateTarget::class);
        $this->addAction('deleteUserTarget', DeleteTarget::class);

        // Sessions
        $this->addAction('createUserSession', CreateSession::class);
        $this->addAction('listUserSessions', ListSessions::class);
        $this->addAction('deleteUserSession', DeleteSession::class);
        $this->addAction('deleteUserSessions', DeleteSessions::class);

        // Tokens
        $this->addAction('createUserToken', CreateToken::class);
        $this->addAction('createUserJWT', CreateJWT::class);

        // Memberships
        $this->addAction('listUserMemberships', ListMemberships::class);

        // Identities
        $this->addAction('listIdentities', ListIdentities::class);
        $this->addAction('deleteIdentity', DeleteIdentity::class);

        // MFA
        $this->addAction('updateUserMFA', UpdateMFA::class);
        $this->addAction('listUserMFAFactors', ListFactors::class);
        $this->addAction('getUserMFAChallenge', GetMFAChallenge::class);
        $this->addAction('getUserMFARecoveryCodes', GetRecoveryCodes::class);
        $this->addAction('createUserMFARecoveryCodes', CreateRecoveryCodes::class);
        $this->addAction('updateUserMFARecoveryCodes', UpdateRecoveryCodes::class);
        $this->addAction('deleteUserMFAAuthenticator', DeleteAuthenticator::class);
        $this->addAction('listUserPasskeys', ListPasskeys::class);
        $this->addAction('getUserPasskey', GetPasskey::class);
        $this->addAction('updateUserPasskey', UpdatePasskey::class);
        $this->addAction('deleteUserPasskey', DeletePasskey::class);
    }
}
