<?php

namespace Appwrite\Platform\Modules\Proxy\Services;

use Appwrite\Platform\Modules\Proxy\Http\Rules\API\Create as CreateAPIRule;
use Appwrite\Platform\Modules\Proxy\Http\Rules\Delete as DeleteRule;
use Appwrite\Platform\Modules\Proxy\Http\Rules\Function\Create as CreateFunctionRule;
use Appwrite\Platform\Modules\Proxy\Http\Rules\Function\Update as UpdateFunctionRule;
use Appwrite\Platform\Modules\Proxy\Http\Rules\Get as GetRule;
use Appwrite\Platform\Modules\Proxy\Http\Rules\Redirect\Create as CreateRedirectRule;
use Appwrite\Platform\Modules\Proxy\Http\Rules\Redirect\Update as UpdateRedirectRule;
use Appwrite\Platform\Modules\Proxy\Http\Rules\Site\Create as CreateSiteRule;
use Appwrite\Platform\Modules\Proxy\Http\Rules\Site\Update as UpdateSiteRule;
use Appwrite\Platform\Modules\Proxy\Http\Rules\Status\Update as UpdateRuleStatus;
use Appwrite\Platform\Modules\Proxy\Http\Rules\XList as ListRules;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        // Rules
        $this->addAction('createAPIRule', CreateAPIRule::class);
        $this->addAction('createSiteRule', CreateSiteRule::class);
        $this->addAction('createFunctionRule', CreateFunctionRule::class);
        $this->addAction('createRedirectRule', CreateRedirectRule::class);
        $this->addAction('getRule', GetRule::class);
        $this->addAction('listRules', ListRules::class);
        $this->addAction('deleteRule', DeleteRule::class);
        $this->addAction('updateRuleVerification', UpdateRuleStatus::class);
        $this->addAction('updateSiteRule', UpdateSiteRule::class);
        $this->addAction('updateFunctionRule', UpdateFunctionRule::class);
        $this->addAction('updateRedirectRule', UpdateRedirectRule::class);
    }
}
