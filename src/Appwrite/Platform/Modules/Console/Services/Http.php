<?php

namespace Appwrite\Platform\Modules\Console\Services;

use Appwrite\Platform\Modules\Console\Http\Assistant\Create as CreateAssistantQuery;
use Appwrite\Platform\Modules\Console\Http\Assistant\Feedback as CreateAssistantFeedback;
use Appwrite\Platform\Modules\Console\Http\Init\API;
use Appwrite\Platform\Modules\Console\Http\Init\Web;
use Appwrite\Platform\Modules\Console\Http\Redirects\Auth\Get as RedirectAuth;
use Appwrite\Platform\Modules\Console\Http\Redirects\Card\Get as RedirectCard;
use Appwrite\Platform\Modules\Console\Http\Redirects\Invite\Get as RedirectInvite;
use Appwrite\Platform\Modules\Console\Http\Redirects\Login\Get as RedirectLogin;
use Appwrite\Platform\Modules\Console\Http\Redirects\MFA\Get as RedirectMFA;
use Appwrite\Platform\Modules\Console\Http\Redirects\Recover\Get as RedirectRecover;
use Appwrite\Platform\Modules\Console\Http\Redirects\Register\Get as RedirectRegister;
use Appwrite\Platform\Modules\Console\Http\Redirects\Root\Get as RedirectRoot;
use Appwrite\Platform\Modules\Console\Http\Resources\Get as GetResourceAvailability;
use Appwrite\Platform\Modules\Console\Http\Variables\Get as GetVariables;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        // API and Web init hooks!
        $this->addAction(API::getName(), new API());
        $this->addAction(Web::getName(), new Web());

        $this->addAction(GetVariables::getName(), new GetVariables());
        $this->addAction(CreateAssistantQuery::getName(), new CreateAssistantQuery());
        $this->addAction(CreateAssistantFeedback::getName(), new CreateAssistantFeedback());
        $this->addAction(GetResourceAvailability::getName(), new GetResourceAvailability());

        // web redirects to /console
        $this->addAction(RedirectRoot::getName(), new RedirectRoot());
        $this->addAction(RedirectAuth::getName(), new RedirectAuth());
        $this->addAction(RedirectInvite::getName(), new RedirectInvite());
        $this->addAction(RedirectLogin::getName(), new RedirectLogin());
        $this->addAction(RedirectMFA::getName(), new RedirectMFA());
        $this->addAction(RedirectCard::getName(), new RedirectCard());
        $this->addAction(RedirectRecover::getName(), new RedirectRecover());
        $this->addAction(RedirectRegister::getName(), new RedirectRegister());
    }
}
