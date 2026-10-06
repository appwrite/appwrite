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
        $this->addAction(CreateUser::getName(), new CreateUser());
        $this->addAction(GetUser::getName(), new GetUser());
        $this->addAction(ListUsers::getName(), new ListUsers());
        $this->addAction(DeleteUser::getName(), new DeleteUser());

        // Users with pre-hashed passwords
        $this->addAction(CreateBcryptUser::getName(), new CreateBcryptUser());
        $this->addAction(CreateMD5User::getName(), new CreateMD5User());
        $this->addAction(CreateArgon2User::getName(), new CreateArgon2User());
        $this->addAction(CreateSHAUser::getName(), new CreateSHAUser());
        $this->addAction(CreatePHPassUser::getName(), new CreatePHPassUser());
        $this->addAction(CreateScryptUser::getName(), new CreateScryptUser());
        $this->addAction(CreateScryptModifiedUser::getName(), new CreateScryptModifiedUser());

        // Properties
        $this->addAction(UpdateStatus::getName(), new UpdateStatus());
        $this->addAction(UpdateLabels::getName(), new UpdateLabels());
        $this->addAction(UpdateImpersonator::getName(), new UpdateImpersonator());
        $this->addAction(UpdateName::getName(), new UpdateName());
        $this->addAction(UpdatePassword::getName(), new UpdatePassword());
        $this->addAction(UpdateEmail::getName(), new UpdateEmail());
        $this->addAction(UpdatePhone::getName(), new UpdatePhone());
        $this->addAction(UpdateEmailVerification::getName(), new UpdateEmailVerification());
        $this->addAction(UpdatePhoneVerification::getName(), new UpdatePhoneVerification());

        // Preferences
        $this->addAction(GetPrefs::getName(), new GetPrefs());
        $this->addAction(UpdatePrefs::getName(), new UpdatePrefs());

        // Targets
        $this->addAction(CreateTarget::getName(), new CreateTarget());
        $this->addAction(GetTarget::getName(), new GetTarget());
        $this->addAction(ListTargets::getName(), new ListTargets());
        $this->addAction(UpdateTarget::getName(), new UpdateTarget());
        $this->addAction(DeleteTarget::getName(), new DeleteTarget());

        // Sessions
        $this->addAction(CreateSession::getName(), new CreateSession());
        $this->addAction(ListSessions::getName(), new ListSessions());
        $this->addAction(DeleteSession::getName(), new DeleteSession());
        $this->addAction(DeleteSessions::getName(), new DeleteSessions());

        // Tokens
        $this->addAction(CreateToken::getName(), new CreateToken());
        $this->addAction(CreateJWT::getName(), new CreateJWT());

        // Memberships
        $this->addAction(ListMemberships::getName(), new ListMemberships());

        // Identities
        $this->addAction(ListIdentities::getName(), new ListIdentities());
        $this->addAction(DeleteIdentity::getName(), new DeleteIdentity());

        // MFA
        $this->addAction(UpdateMFA::getName(), new UpdateMFA());
        $this->addAction(ListFactors::getName(), new ListFactors());
        $this->addAction(GetMFAChallenge::getName(), new GetMFAChallenge());
        $this->addAction(GetRecoveryCodes::getName(), new GetRecoveryCodes());
        $this->addAction(CreateRecoveryCodes::getName(), new CreateRecoveryCodes());
        $this->addAction(UpdateRecoveryCodes::getName(), new UpdateRecoveryCodes());
        $this->addAction(DeleteAuthenticator::getName(), new DeleteAuthenticator());
    }
}
