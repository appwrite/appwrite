<?php

namespace Appwrite\Platform\Modules\Console\Services;

use Appwrite\Platform\Modules\Console\Http\Init\API;
use Appwrite\Platform\Modules\Console\Http\Init\Web;
use Appwrite\Platform\Modules\Console\Http\OAuth2Providers\XList as ListOAuth2Providers;
use Appwrite\Platform\Modules\Console\Http\Redirects\Auth\Get as RedirectAuth;
use Appwrite\Platform\Modules\Console\Http\Redirects\Invite\Get as RedirectInvite;
use Appwrite\Platform\Modules\Console\Http\Redirects\Login\Get as RedirectLogin;
use Appwrite\Platform\Modules\Console\Http\Redirects\MFA\Get as RedirectMFA;
use Appwrite\Platform\Modules\Console\Http\Redirects\Recover\Get as RedirectRecover;
use Appwrite\Platform\Modules\Console\Http\Redirects\Register\Get as RedirectRegister;
use Appwrite\Platform\Modules\Console\Http\Redirects\Root\Get as RedirectRoot;
use Appwrite\Platform\Modules\Console\Http\Resources\Get as GetResourceAvailability;
use Appwrite\Platform\Modules\Console\Http\Scopes\Organization\XList as ListOrganizationScopes;
use Appwrite\Platform\Modules\Console\Http\Scopes\Project\XList as ListKeyScopes;
use Appwrite\Platform\Modules\Console\Http\Templates\Email\Get as GetEmailTemplate;
use Appwrite\Platform\Modules\Console\Http\Variables\Get as GetVariables;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        // API and Web init hooks!
        $this->addAction(API::getName(), API::class);
        $this->addAction(Web::getName(), Web::class);

        $this->addAction(GetVariables::getName(), GetVariables::class);
        $this->addAction(GetEmailTemplate::getName(), GetEmailTemplate::class);
        $this->addAction(ListOAuth2Providers::getName(), ListOAuth2Providers::class);
        $this->addAction(ListKeyScopes::getName(), ListKeyScopes::class);
        $this->addAction(ListOrganizationScopes::getName(), ListOrganizationScopes::class);
        $this->addAction(GetResourceAvailability::getName(), GetResourceAvailability::class);

        // web redirects to the console
        $this->addAction(RedirectRoot::getName(), RedirectRoot::class);
        $this->addAction(RedirectAuth::getName(), RedirectAuth::class);
        $this->addAction(RedirectInvite::getName(), RedirectInvite::class);
        $this->addAction(RedirectLogin::getName(), RedirectLogin::class);
        $this->addAction(RedirectMFA::getName(), RedirectMFA::class);
        $this->addAction(RedirectRecover::getName(), RedirectRecover::class);
        $this->addAction(RedirectRegister::getName(), RedirectRegister::class);
    }
}
