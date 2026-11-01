<?php

namespace Appwrite\Platform\Modules\Proxy\Services;

use Appwrite\Platform\Modules\Proxy\Http\Rules\API\Create as CreateAPIRule;
use Appwrite\Platform\Modules\Proxy\Http\Rules\Delete as DeleteRule;
use Appwrite\Platform\Modules\Proxy\Http\Rules\Function\Create as CreateFunctionRule;
use Appwrite\Platform\Modules\Proxy\Http\Rules\Get as GetRule;
use Appwrite\Platform\Modules\Proxy\Http\Rules\Redirect\Create as CreateRedirectRule;
use Appwrite\Platform\Modules\Proxy\Http\Rules\Site\Create as CreateSiteRule;
use Appwrite\Platform\Modules\Proxy\Http\Rules\Status\Update as UpdateRuleStatus;
use Appwrite\Platform\Modules\Proxy\Http\Rules\XList as ListRules;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        // Rules
        $this->addAction(CreateAPIRule::getName(), CreateAPIRule::class);
        $this->addAction(CreateSiteRule::getName(), CreateSiteRule::class);
        $this->addAction(CreateFunctionRule::getName(), CreateFunctionRule::class);
        $this->addAction(CreateRedirectRule::getName(), CreateRedirectRule::class);
        $this->addAction(GetRule::getName(), GetRule::class);
        $this->addAction(ListRules::getName(), ListRules::class);
        $this->addAction(DeleteRule::getName(), DeleteRule::class);
        $this->addAction(UpdateRuleStatus::getName(), UpdateRuleStatus::class);
    }
}
