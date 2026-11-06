<?php

namespace Appwrite\Platform\Modules\Console\Services;

use Appwrite\Platform\Modules\Console\Http\Alerts\Unsubscribe\Create as CreateAlertsUnsubscribe;
use Appwrite\Platform\Modules\Console\Http\Alerts\Unsubscribe\Delete as DeleteAlertsUnsubscribe;
use Appwrite\Platform\Modules\Console\Http\Alerts\Unsubscribe\Get as GetAlertsUnsubscribe;
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
use Appwrite\Platform\Modules\Console\Http\Versions\Get as GetVersions;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        // API and Web init hooks!
        $this->addAction('consoleAPI', API::class);
        $this->addAction('consoleWeb', Web::class);

        $this->addAction('getVariables', GetVariables::class);
        $this->addAction('getConsoleEmailTemplate', GetEmailTemplate::class);
        $this->addAction('listOAuth2Providers', ListOAuth2Providers::class);
        $this->addAction('listConsoleProjectScopes', ListKeyScopes::class);
        $this->addAction('listConsoleOrganizationScopes', ListOrganizationScopes::class);
        $this->addAction('getResource', GetResourceAvailability::class);
        $this->addAction('getVersions', GetVersions::class);

        // Alert email unsubscribe links, which carry a signed token instead of a session
        $this->addAction('getAlertsUnsubscribe', GetAlertsUnsubscribe::class);
        $this->addAction('createAlertsUnsubscribe', CreateAlertsUnsubscribe::class);
        $this->addAction('deleteAlertsUnsubscribe', DeleteAlertsUnsubscribe::class);

        // web redirects to the console
        $this->addAction('consoleRedirectRoot', RedirectRoot::class);
        $this->addAction('consoleRedirectAuth', RedirectAuth::class);
        $this->addAction('consoleRedirectInvite', RedirectInvite::class);
        $this->addAction('consoleRedirectLogin', RedirectLogin::class);
        $this->addAction('consoleRedirectMFA', RedirectMFA::class);
        $this->addAction('consoleRedirectRecover', RedirectRecover::class);
        $this->addAction('consoleRedirectRegister', RedirectRegister::class);
    }
}
